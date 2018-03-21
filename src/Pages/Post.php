<?php
/**
 * Holds the post handler.
 */

namespace Miiverse\Pages;

use Miiverse\Community\Community;
use Miiverse\CurrentSession;
use Miiverse\DB;
use Miiverse\User;

/**
 * Post handler.
 *
 * @author Repflez
 */
class Post extends Page
{
    /**
     * Creates or replies to a post.
     */
    public function submit()
    {
        $kind = $_POST['kind'] ?? null;

        $user = CurrentSession::$user;
        $userid = $user->id;

        if (!$userid) {
            exit;
        }

        if ($kind == 'post') {
            $title_id = $_POST['olive_title_id'];
            $id = $_POST['olive_community_id'];
            $feeling = $_POST['feeling_id'];
            $spoiler = $_POST['is_spoiler'] ?? 0;
            $type = $_POST['_post_type'];

            $meta = DB::table('communities')
                        ->where('id', $id)
                        ->first();

            if (!$meta) {
                return view('errors/404');
            }

            switch ($type) {
                case 'body':
                    $body = $_POST['body'];

                    if (!$meta->is_redesign) {
                        $postId = DB::table('posts')
                            ->insertGetId([
                                'community' => $id,
                                'content'   => $body,
                                'feeling'   => $feeling,
                                'user_id'   => $userid,
                                'spoiler'   => intval($spoiler),
                            ]);
                    } else {
                        $category_id = $_POST['topic_category_id'];
                        $title = $_POST['topic_title'];
                        $is_open = 1;

                        $postId = DB::table('posts')
                            ->insertGetId([
                                'community'   => $id,
                                'content'     => $body,
                                'feeling'     => $feeling,
                                'user_id'     => $userid,
                                'spoiler'     => intval($spoiler),
                                'category_id' => $category_id,
                                'title'       => $title,
                                'is_open'     => $is_open,
                                'is_redesign' => $meta->is_redesign,
                            ]);
                    }
                    break;
                case 'painting':
                    $painting = base64_decode($_POST['painting']);
                    $painting_name = $userid.'-'.time().'.png';

                    file_put_contents(path('public/img/drawings/'.$painting_name), $painting);

                    $postId = DB::table('posts')
                        ->insertGetId([
                            'community'   => $id,
                            'image'       => $painting_name,
                            'feeling'     => $feeling,
                            'user_id'     => $userid,
                            'spoiler'     => intval($spoiler),
                            'is_redesign' => $meta->is_redesign,
                        ]);
                    break;
                default:
                    break;
            }

            if (!$user->posted) {
                DB::table('users')
                    ->where('user_id', '=', $userid)
                    ->update(['posted' => 1]);
            }

            DB::table('users')
                ->where('user_id', '=', $userid)
                ->increment('posts');

            header('Content-Length: 0', true);
            header('Connection: Keep-Alive', true);
            header('Keep-Alive: timeout=5, max=100', true);
            redirect(route('title.community', ['tid' => hashid($title_id), 'id' => hashid($id)]));
        } elseif ($kind = 'reply') {
            $post_id = $_POST['olive_post_id'];
            $feeling = $_POST['feeling_id'];
            $spoiler = $_POST['is_spoiler'] ?? 0;
            $type = $_POST['_post_type'];

            switch ($type) {
                case 'body':
                    $body = $_POST['body'];

                    DB::table('comments')
                        ->insert([
                            'post'    => $post_id,
                            'content' => $body,
                            'feeling' => $feeling,
                            'user'    => $userid,
                            'spoiler' => intval($spoiler),
                        ]);
                    break;
                case 'painting':
                    $painting = base64_decode($_POST['painting']);
                    $painting_name = $userid.'-'.time().'.png';

                    file_put_contents(path('public/img/drawings/'.$painting_name), $painting);

                    DB::table('comments')
                        ->insert([
                            'post'    => $post_id,
                            'image'   => $painting_name,
                            'feeling' => $feeling,
                            'user'    => $userid,
                            'spoiler' => intval($spoiler),
                        ]);
                    break;
            }

            if (!$user->posted) {
                DB::table('users')
                    ->where('user_id', '=', $userid)
                    ->update(['posted' => 1]);
            }

            DB::table('posts')
                ->where('id', '=', $post_id)
                ->increment('comments');

            header('Content-Length: 0', true);
            header('Connection: Keep-Alive', true);
            header('Keep-Alive: timeout=5, max=100', true);
            redirect(route('post.show', ['id' => hashid($post_id)]));
        }
        exit;
    }

    /**
     * Shows an individual post.
     */
    public function show(string $id) : string
    {
        $post_id = dehashid($id);
        $comments = [];
        $likers = [];
        $verified_ranks = [
            config('rank.verified'),
            config('rank.mod'),
            config('rank.admin'),
        ];

        $post = DB::table('posts')
                        ->where('id', $post_id)
                        ->first();

        $post->community = new Community($post->community);
        $post->user = User::construct($post->user_id);

        if ($post->user->hasRanks($verified_ranks)) {
            if (empty($post->user->title)) {
                $post->user->organization = $post->user->mainRank->name();
            } else {
                $post->user->organization = $post->user->title;
            }
        }

        $post->verified = $post->user->hasRanks($verified_ranks);
        $post->liked = (bool) DB::table('empathies')
                                ->where([
                                    ['type', 0], // Posts are type 0
                                    ['id', $post->id],
                                    ['user', CurrentSession::$user->id],
                                ])
                                ->count();

        if ($post->liked) {
            $like_limit = 11;
        } else {
            $like_limit = 12;
        }

        $likers_tmp = DB::table('empathies')
                        ->where([
                            ['type', 0],
                            ['id', $post->id],
                            ['user', '<>', CurrentSession::$user->id],
                        ])
                        ->limit($like_limit)
                        ->pluck('user');

        foreach ($likers_tmp as $liker) {
            $liker = User::construct($liker);

            $likers[] = [
                'data'     => $liker,
                'verified' => $liker->hasRanks($verified_ranks),
            ];
        }

        $post->likers = $likers;
        $post->likerCount = DB::table('empathies')
                                ->where([
                                    ['type', 0],
                                    ['id', $post->id],
                                    ['user', '<>', CurrentSession::$user->id],
                                ])
                                ->count();

        $comments_temp = DB::table('comments')
                    ->where('post', $post->id)
                    ->orderBy('created', 'asc')
                    ->limit(20)
                    ->get(['id', 'created', 'edited', 'deleted', 'user', 'content', 'type', 'image', 'feeling', 'spoiler', 'empathies']);

        $feeling = ['normal', 'happy', 'like', 'surprised', 'frustrated', 'puzzled'];
        $feelingText = ['Yeah!', 'Yeah!', 'Yeah♥', 'Yeah!?', 'Yeah...', 'Yeah...'];

        if ($comments_temp) {
            foreach ($comments_temp as $comment) {
                $comment->user = User::construct($comment->user);
                $comment->verified = $comment->user->hasRanks($verified_ranks);
                $comment->liked = (bool) DB::table('empathies')
                                        ->where([
                                            ['type', 1], // Comments are type 1
                                            ['id', $comment->id],
                                            ['user', CurrentSession::$user->id],
                                        ])
                                        ->count();
                $comments[] = $comment;
            }
        }

        return view('posts/view', compact('post', 'comments', 'feeling', 'feelingText'));
    }

    /**
     * Reply form for posts.
     *
     * @return string
     */
    public function reply($id) : string
    {
        $post = dehashid($id);

        if (!is_array($post)) {
            return view('errors/404');
        }

        $meta = DB::table('posts')
                    ->where('id', $post)
                    ->first();

        if (!$meta) {
            return view('errors/404');
        }

        $community = DB::table('communities')
                    ->where('id', $meta->community)
                    ->first();

        if (!$community) {
            return view('errors/404');
        }

        return view('posts/reply', compact('meta', 'community'));
    }

    /**
     * Create a Yeah for this post.
     *
     * @var string
     *
     * @return string
     */
    public function yeahs(string $post_id) : string
    {
        $post_id = dehashid($post_id);

        $post = DB::table('posts')
                    ->where('id', $post_id)
                    ->first();

        if ($post) {
            DB::table('empathies')
                ->insert([
                    'type' => 0,
                    'id'   => $post->id,
                    'user' => CurrentSession::$user->id,
                ]);

            DB::table('posts')
                ->where('id', $post_id)
                ->increment('empathies');
        } else {
            header('HTTP/1.1 403 Forbidden');
        }

        return '';
    }

    /**
     * Remove a Yeah for this post.
     *
     * @var string
     *
     * @return string
     */
    public function removeYeahs(string $post_id) : string
    {
        $post_id = dehashid($post_id);

        $post = DB::table('posts')
                    ->where('id', $post_id)
                    ->first();

        if ($post) {
            DB::table('empathies')
                ->where([
                    'type' => 0,
                    'id'   => $post->id,
                    'user' => CurrentSession::$user->id,
                ])
                ->delete();

            DB::table('posts')
                ->where('id', $post_id)
                ->decrement('empathies');
        } else {
            header('HTTP/1.1 403 Forbidden');
        }

        return '';
    }

    /**
     * Create a Yeah for this comment.
     *
     * @var string
     *
     * @return string
     */
    public function replyYeahs(string $post_id) : string
    {
        $post_id = dehashid($post_id);

        $post = DB::table('comments')
                    ->where('id', $post_id)
                    ->first();

        if ($post) {
            DB::table('empathies')
                ->insert([
                    'type' => 1,
                    'id'   => $post->id,
                    'user' => CurrentSession::$user->id,
                ]);

            DB::table('comments')
                ->where('id', $post_id)
                ->increment('empathies');
        } else {
            header('HTTP/1.1 403 Forbidden');
        }

        return '';
    }

    /**
     * Remove a Yeah for this comment.
     *
     * @var string
     *
     * @return string
     */
    public function replyRemoveYeahs(string $post_id) : string
    {
        $post_id = dehashid($post_id);

        $post = DB::table('comments')
            ->where('id', $post_id)
            ->first();

        if ($post) {
            DB::table('empathies')
                ->where([
                    'type' => 1,
                    'id'   => $post->id,
                    'user' => CurrentSession::$user->id,
                ])
                ->delete();

            DB::table('comments')
                ->where('id', $post_id)
                ->decrement('empathies');
        } else {
            header('HTTP/1.1 403 Forbidden');
        }

        return '';
    }
}
