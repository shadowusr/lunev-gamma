<?php

namespace App\Http\Controllers\Events;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\User;
use Illuminate\Http\Request;

class EventsHandlerController extends Controller
{
    public function actionPermitted(Group $group, User $user) {
        return $group->owner_id == $user->id;
    }

    public function api($method, $params) {
        $api = new Client([
            'base_uri' => 'https://api.vk.com/method/',
            'timeout' => 2.0,
            'curl' => [
                CURLOPT_SSL_VERIFYPEER => false, // TEMPORARY TODO
            ]
        ]);

        return json_decode($api->post($method, [
            'form_params' => $params,
        ])->getBody()->getContents(), true);
    }

    public function handle(Request $request) {
        //var_dump($request->all()); return;
        //$group = Group::findOrFail($request->get('group_id'));

        if (env('VK_GROUP_ID') != $request->get('group_id')) {
            return;
        }

        if (env('VK_SECRET_KEY') != $request->get('secret')) {
            return;
        }

        switch ($request->get('type')) {
            case 'confirmation':
                return env('VK_CONFIRMATION_CODE');
            case 'message_new':
                $message = $request->get('object');
                if ($message['text'][0] == '/') {
                    $this->handleCommand($request->all());
                }
                break;
        }
        return response('ok');
    }

    public function handleCommand($event) {
        preg_match_all("/`(.*?)(?:`|$)|([\\S]+)/u", $event['object']['text'], $command);
        $command = $command[0];
        $command = str_replace('`', '', $command);
        $command = str_replace('<br>', "\n", $command);

        $message = $event['object'];

        switch ($command[0]) {
            case '/group':
                if (!isset($command[1])) {
                    return;
                }
                switch ($command[1]) {
                    case 'select':
                        if (count($command) != 3) {
                            return;
                        }
                        $group = Group::find($command[2]);
                        $user = User::find($message['peer_id']);
                        if (!$user) {
                            return;
                        }
                        if (!$group) {
                            return;
                        }
                        if (!$this->actionPermitted($group, $user)) {
                            return;
                        }
                        $user->selected_group = $group->id;
                        $user->save();
                        $this->api('messages.send', [
                            'v' => '5.100',
                            'access_token' => env('VK_GROUP_ACCESS_TOKEN'),
                            'peer_id' => $user->id,
                            'message' => '✔ Группа выбрана.'
                        ]);
                        break;
                }
                break;
            case '/layout': // view or set post layout
                $group = Group::find($command[2]);
                $user = User::find($message['peer_id']);
                if (!$user) {
                    return;
                }
                if (!$group) {
                    return;
                }
                if (!$this->actionPermitted($group, $user)) {
                    return;
                }
                if (count($command) == 1) {
                    $this->api('messages.send', [
                        'v' => '5.100',
                        'access_token' => env('VK_GROUP_ACCESS_TOKEN'),
                        'peer_id' => $user->id,
                        'message' => $group->post_layout,
                    ]);
                } elseif (count($command) == 2) {
                    $group->post_layout = $command[1];
                    $group->save();
                    $this->api('messages.send', [
                        'v' => '5.100',
                        'access_token' => env('VK_GROUP_ACCESS_TOKEN'),
                        'peer_id' => $user->id,
                        'message' => '✔ Шаблон поста изменен.'
                    ]);
                } else {
                    return;
                }
                break;
            case '/ping':
                if ($message['peer_id'] == 580598350) {
                    $this->api('messages.send', [
                        'v' => '5.100',
                        'access_token' => env('VK_GROUP_ACCESS_TOKEN'),
                        'peer_id' => $message['peer_id'],
                        'message' => '✔ Online.'
                    ]);
                }
                break;
            /*case '/config':
                break;*/
        }
    }
}
