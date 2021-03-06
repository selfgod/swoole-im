<?php
/**
 * Created by PhpStorm.
 * User: yuzhang
 * Date: 2018/4/14
 * Time: 下午5:08
 */
namespace App\WebsocketController;
use App\Exception\Websocket\TokenException;
use App\Model\ChatRecord;
use App\Model\GroupMember;
use App\Service\ChatService;
use App\Service\Common;
use App\Service\FriendService;
use App\Service\UserCacheService;
use App\Model\Friend as FriendModel;
use EasySwoole\Core\Component\Logger;
use EasySwoole\Core\Swoole\ServerManager;
use App\Model\GroupUser;
use App\Service\GroupUserMemberService;
class OnOpen extends BaseWs
{
    /*
     * 用户连线后初始化
     * 传参：token
     * 1. 获取用户 fd
     * 2. 初始化所有相关缓存
     * 3. 向所有好友发送上线提醒
     * 4. 向所有群聊发送上线提醒
     */
    public function init()
    {
        $user = $this->getUserInfo();
        if(!$user)
        {
            $err = (new TokenException())->getMsg();
            $this->response()->write(json_encode($err));
            return;
        }
        //判断是否有其他地方已登陆
        $userFd = UserCacheService::getFdByNum($user['user']['number']);
        if($userFd != (int)$user['fd'])
        {
            $this->push($userFd , ['type'=>'ws','method'=> 'belogin','data'=> 'logout']);
        }
        //初始化所有相关缓存
        $this->saveCache($user);

        // 查出所有好友，查所有好友的在线状态，向所有好友发送上线提醒
        $this->sendOnlineMsg($user);

        // 记录访问日志
        $this->saveAccessLog();

        //检查离线消息
        $this->checkOfflineRecord($user);

        $this->sendMsg(['method'=>'initok','data'=>$user['user']]);
    }
    public function push($fd,$data)
    {
        $server = ServerManager::getInstance()->getServer();
        if($server->getClientInfo($fd))
            $server->push($fd,json_encode($data));
    }

    /**
     * @param $user
     * 检查离线消息
     */
    public function checkOfflineRecord($self)
    {
        $record = ChatRecord::getAllNoReadRecord($self['user']['id']);
        $sendData = [];
        $data['to'] = $self;
        foreach ($record as $k => $v)
        {
            $user['user'] = $v['user'];
            $data['from'] = $user;
            $data['data'] = $v['data'];
            $sendData[] = $data;
        }
        ChatService::sendOfflineMsg($self['fd'],$sendData);
    }
    private function saveCache($user)
    {
        // 更新用户在线状态缓存（ 添加 fd 字段 ）
        UserCacheService::saveNumToFd($user['user']['number'], $user['fd']);
        // 添加 fd 与 token 关联缓存，close 时可以销毁 fd 相关缓存
        UserCacheService::saveTokenByFd($user['fd'], $user['token']);
        // 查找用户所在所有组，初始化组缓存
        $groups = GroupMember::getGroups(['user_number'=>$user['user']['number']]);
        if(!$groups->isEmpty())
        {
            foreach ($groups as $val)
            {
                UserCacheService::setGroupFds($val->gnumber, $user['fd']);
            }
        }
    }
    /*
     * 发送上线通知
     */
    private function sendOnlineMsg($user)
    {
        // 获取分组好友
        $groups = GroupUser::getAllFriends($user['user']['id']);
        $friends = GroupUserMemberService::getFriends($groups);
        $data = [
            'type'      => 'ws',
            'method'    => 'friendOnLine',
            'data'      => [
                'number'    => $user['user']['id'],
                'nickname'  => $user['user']['nickname'],
            ]
        ];
        foreach ($friends as $val)
        {
            foreach ($val['list'] as $v)
            {
                if($v['status'])
                {
                    $fd = UserCacheService::getFdByNum($v['number']);
                    $this->push($fd,$data);
                }
            }
        }
    }
    /*
     * 存储访问日志
     */
    private function saveAccessLog()
    {
        $server = ServerManager::getInstance()->getServer();
        $info = $server->connection_info($fd = $this->client()->getFd());
        $file_content = $info['remote_ip'];
        Logger::getInstance()->log($file_content,'access');
    }
}