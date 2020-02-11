<?php

namespace App\Console\Commands;

use App\Models\Group;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Sandbox\SecurityPolicy;

class RefreshList extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'list:refresh';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh lists in all groups.';

    /**
     * Return last post which was made by bot and has to be edited.
     *
     * @param Group $group
     * @return mixed
     */
    private function getLastPostIdByGroup(Group $group) {
        return $group->last_post_id;
    }


    private function generatePostText(Group $group, $users) {
        // Preparing sandbox for template
        $whitelist['tags'] = ['if', 'for'];
        $whitelist['filters'] = ['escape'];
        $whitelist['methods'] = [];
        $whitelist['properties'] = [];
        $whitelist['functions'] = [];
        $policy = new SecurityPolicy($whitelist['tags'], $whitelist['filters'], $whitelist['methods'], $whitelist['properties'], $whitelist['functions']);
        $sandbox = new SandboxExtension($policy, true);

        // Setting up Twig engine
        $twigLoader = new ArrayLoader([ 'text' => $group->post_layout ]);

        $twig = new \Twig\Environment($twigLoader);
        $twig->addExtension($sandbox);

        return $twig->render('text', ['users' => $users]);
    }

    /**
     * Create a new command instance.
     *
     * @return void
     */

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        /*
        Algorithm (for each group):
        1. Get bot's post with list and reposts.
        2. For each repost get number of views on user's wall.
        3. Sort users according to number of views.
        4. Generate twig template.
        5. Edit post with new template.
        */

        /*
         * TODO:
         * Refactor code: decompose handle method into many slim methods.
         * Write unit tests.
         * */

        $api = new Client([
            'base_uri' => 'https://api.vk.com/method/',
            'timeout' => 2.0,
            'curl' => [
                CURLOPT_SSL_VERIFYPEER => false, // TEMPORARY
            ]
        ]);

        $groups = Group::all();

        foreach ($groups as $group) {
            var_dump('GROUP', $group->id);
            $lastPostId = $this->getLastPostIdByGroup($group);
            if (!$lastPostId) {
                continue;
            }
            $reposts = json_decode($api->post('wall.getReposts', [
                'form_params' => [
                    'owner_id' => $group->id,
                    'post_id' => $lastPostId,
                    'count' => 100,
                    'v' => '5.103',
                    'access_token' => env('VK_BOT_ACCESS_TOKEN'),
                ],
            ])->getBody()->getContents(), true);

            var_dump('REPOSTS', $reposts);

            if (!$reposts || !isset($reposts['response']['items'])) {
                Log::error('Unable to get reposts data for group vk.com/club' . -$group->id . '. Dump of vk response: ' . print_r($reposts, true));
                continue;
            }

            $viewsByUserId = []; // key = user id, value = views count.

            foreach ($reposts['response']['items'] as $repost) {
                $viewsByUserId[$repost['from_id']] = $repost['views']['count'] ?? 0;
            }

            $users = json_decode($api->post('users.get', [
                'form_params' => [
                    'user_ids' => implode(',', array_column($reposts['response']['items'], 'from_id')),
                    'lang' => 'ru',
                    'v' => '5.103',
                    'access_token' => env('VK_BOT_ACCESS_TOKEN'),
                ],
            ])->getBody()->getContents(), true);

            var_dump('users', $users);

            if (!$users || !isset($users['response'])) {
                Log::error('Unable to get users data for group vk.com/club' . -$group->id . '. Dump of vk response: ' . print_r($users, true));
                continue;
            }

            $users = $users['response'];

            foreach ($users as &$user) {
                $user['views'] = $viewsByUserId[$user['id']];
            }

            usort($users, function ($a, $b) {
                return $b['views'] <=> $a['views'];
            });

            var_dump('users final', $users);
            $newText = $this->generatePostText($group, $users);

            var_dump('NEW text', $newText);

            if (time() - $group->last_post_time > 24 * 60 * 60 - 30) {
                $result = json_decode($api->post('wall.delete', [
                    'form_params' => [
                        'owner_id' => $group->id,
                        'post_id' => $lastPostId,
                        'v' => '5.103',
                        'access_token' => env('VK_BOT_ACCESS_TOKEN'),
                    ],
                ])->getBody()->getContents(), true);

                if (!$result || !isset($result['response'])) {
                    Log::error('Removing post in group vk.com/club' . -$group->id . ' failed! Dump of vk response: ' . print_r($result, true));
                    continue;
                }

                $result = json_decode($api->post('wall.post', [
                    'form_params' => [
                        'owner_id' => $group->id,
                        'from_group' => 1,
                        'message' => $newText,
                        'v' => '5.103',
                        'access_token' => env('VK_BOT_ACCESS_TOKEN'),
                    ],
                ])->getBody()->getContents(), true);

                var_dump('result', $newText);


                if (!$result || !isset($result['response'])) {
                    Log::error('Making post in group vk.com/club' . -$group->id . ' failed! Dump of vk response: ' . print_r($result, true));
                    continue;
                }

                $group->last_post_id = $result['response']['post_id'];
                $group->last_post_time = time();
                $group->save();
                $lastPostId = $this->getLastPostIdByGroup($group);

                $result = json_decode($api->post('wall.pin', [
                    'form_params' => [
                        'owner_id' => $group->id,
                        'post_id' => $lastPostId,
                        'v' => '5.103',
                        'access_token' => env('VK_BOT_ACCESS_TOKEN'),
                    ],
                ])->getBody()->getContents(), true);

                if (!$result || !isset($result['response'])) {
                    Log::error('Pinning post in group vk.com/club' . -$group->id . ' failed! Dump of vk response: ' . print_r($result, true));
                    continue;
                }

                Log::info('Post was updated successfully for group vk.com/club' . -$group->id);
            } else {
                $result = json_decode($api->post('wall.edit', [
                    'form_params' => [
                        'owner_id' => $group->id,
                        'post_id' => $lastPostId,
                        'message' => $newText,
                        'v' => '5.103',
                        'access_token' => env('VK_BOT_ACCESS_TOKEN'),
                    ],
                ])->getBody()->getContents(), true);

                if (!$result || !isset($result['response'])) {
                    Log::error('Editing post in group vk.com/club' . -$group->id . ' failed! Dump of vk response: ' . print_r($result, true));
                    continue;
                }

                Log::info('Post was updated successfully for group vk.com/club' . -$group->id);
            }
        }
    }
}
