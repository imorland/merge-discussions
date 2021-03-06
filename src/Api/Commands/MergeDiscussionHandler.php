<?php

/*
 * This file is part of fof/merge-discussions.
 *
 * Copyright (c) 2019 FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\MergeDiscussions\Api\Commands;

use Flarum\Discussion\Discussion;
use Flarum\Discussion\DiscussionRepository;
use Flarum\Foundation\ValidationException;
use Flarum\Post\Post;
use Flarum\User\AssertPermissionTrait;
use Flarum\User\UserRepository;
use FoF\MergeDiscussions\Events\DiscussionWasMerged;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Arr;
use Throwable;

class MergeDiscussionHandler
{
    use AssertPermissionTrait;

    /**
     * @var UserRepository
     */
    protected $users;

    /**
     * @var DiscussionRepository
     */
    protected $discussions;

    /**
     * @var Dispatcher
     */
    protected $events;

    /**
     * @param UserRepository       $users
     * @param DiscussionRepository $discussions
     * @param Dispatcher           $events
     */
    public function __construct(
        UserRepository $users,
        DiscussionRepository $discussions,
        Dispatcher $events
    ) {
        $this->users = $users;
        $this->discussions = $discussions;
        $this->events = $events;
    }

    public function handle(MergeDiscussion $command)
    {
        $discussion = $this->discussions->findOrFail($command->discussionId);
        $discussions = [];
        $mergedPosts = [];

        $this->assertCan($command->actor, 'merge', $discussion);

        $posts = $discussion->posts;

        // ##
        // ## Begin destination discussion prep
        // ##

        // To prevent duplicate key issues after the merge, renumber posts in the discussion out of the existing range
        // and leave enough space for the incoming posts to be merged (helps when merging mega threads)

        $incomingPostsCount = 0;

        foreach ($command->ids as $id) {
            $dI = Discussion::find($id);
            foreach ($dI as $discussionIncoming) {
                $c = Post::where('discussion_id', $discussionIncoming->id)->count();
                $incomingPostsCount = $incomingPostsCount + $c;
            }
        }

        // We have to reorder and renumber before the next foreach loop
        $fixNumber = ($posts->last()->number) + 100 + $incomingPostsCount;

        foreach ($posts as $post) {
            $fixNumber++;
            $post->number = $fixNumber;
        }

        $discussion->post_number_index = $fixNumber;

        app('db.connection')->transaction(function () use ($posts, $discussion) {
            $discussion->setRelation('posts', $posts->sortByDesc('number'));
            $discussion->push();
        });

        // ##
        // ## End destination discussion prep
        // ##

        foreach ($command->ids as $id) {
            $d = Discussion::find($id);

            if ($d == null) {
                continue;
            }

            $discussions[] = $d;

            $posts = $posts->merge(
                $mergedPosts[] = $d->posts
            );
        }

        $number = 0;

        $posts->sortBy('created_at')->each(function ($post, $i) use ($discussion, &$number) {
            $number++;

            $post->number = $number;
            $post->discussion_id = $discussion->id;

            $discussion->posts[$i] = $post;
        });

        // @see https://github.com/FriendsOfFlarum/merge-discussions/issues/5
        $discussion->setRelation('posts', $discussion->posts->sortByDesc('number'));

        $discussion->post_number_index = $number;

        if ($command->merge) {
            app('db.connection')->transaction(function () use ($discussions, $discussion) {
                try {
                    $discussion->push();
                } catch (Throwable $e) {
                    $this->catchError($e, 'merging');
                }

                try {
                    $discussion
                        ->refresh()
                        ->refreshCommentCount()
                        ->refreshParticipantCount()
                        ->refreshLastPost()
                        ->setFirstPost($discussion->posts->first())
                        ->save();
                } catch (Throwable $e) {
                    $this->catchError($e, 'updating');
                }

                try {
                    foreach ($discussions as $d) {
                        $d->delete();
                    }
                } catch (Throwable $e) {
                    $this->catchError($e, 'deleting');
                }
            });

            $this->events->dispatch(
                new DiscussionWasMerged($command->actor, Arr::flatten($mergedPosts), $discussion, $discussions)
            );
        }

        return $discussion;
    }

    private function catchError(Throwable $e, string $type)
    {
        $msg = app('translator')->trans("fof-merge-discussions.api.error.{$type}_failed");

        app('log')->error("[fof/merge-discussions] $msg");
        app('log')->error($e);

        throw new ValidationException([
            'fof/merge-discussions' => $msg,
        ]);
    }
}
