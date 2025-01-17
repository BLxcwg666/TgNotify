<?php
require_once __DIR__ . '/Bootstrap.php';

class TgNotify_Action extends Typecho_Widget implements Widget_Interface_Do {
    public function action() {
        $this->init();
        $this->on($this->request->is('do=setWebhook'))->setWebhook ();
        if ($this->_cfg->mode == 0) {
            $this->on($this->request->is('do=CallBack'))->CallBack ();
            $this->on($this->request->is('do=CommentAdd'))->RouteBan ();
            $this->on($this->request->is('do=CommentDel'))->RouteBan ();
            $this->on($this->request->is('do=CommentMark'))->RouteBan ();
        } else {
            $this->on($this->request->is('do=CallBack'))->RouteBan ();
            $this->on($this->request->is('do=' . $GLOBALS['route']['Add']))->CommentAdd ();
            $this->on($this->request->is('do=' . $GLOBALS['route']['Del']))->CommentDel ();
            $this->on($this->request->is('do=' . $GLOBALS['route']['Mark']))->CommentMark ();
        }
    }
    
    /**
     * 初始化
     * 
     * @access private
     * @return $this
     */
    public function init()
    {
        $this->_db = Typecho_Db::get();
        $this->_cfg = $GLOBALS['options']->plugin('TgNotify');
    }
    
    public function RouteBan () {
        exit (json_encode (array ('code' => -1, 'msg' => '原地爆炸，螺旋升天')));
    }

    /**
     * 设置 Webhook
     *
     * @access public
     */
    public function setWebhook () {
        if ($_POST['mode'] == 1) {
            $addUrl = (($GLOBALS['options']->rewrite) ? $GLOBALS['options']->siteUrl : $GLOBALS['options']->siteUrl . 'index.php/') . 'action/CommentEdit?do=' . $GLOBALS['route']['Add'];
            $delUrl = (($GLOBALS['options']->rewrite) ? $GLOBALS['options']->siteUrl : $GLOBALS['options']->siteUrl . 'index.php/') . 'action/CommentEdit?do=' . $GLOBALS['route']['Del'];
            $markUrl = (($GLOBALS['options']->rewrite) ? $GLOBALS['options']->siteUrl : $GLOBALS['options']->siteUrl . 'index.php/') . 'action/CommentEdit?do=' . $GLOBALS['route']['Mark'];

            $text = <<< EOF
评论添加： {$addUrl}
评论删除： {$delUrl}
评论标记： {$markUrl}
EOF;
            $url = 'https://api.telegram.org/bot' . $_POST['token'] . '/sendMessage';
            $postdata = [
            'chat_id' => $_POST['master'],
            'text' => $text
            ];
            
            $ch = curl_init ();
            curl_setopt ($ch, CURLOPT_URL, $url);
            curl_setopt ($ch, CURLOPT_POSTFIELDS, $postdata);
            curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt ($ch, CURLOPT_RETURNTRANSFER, true);
            $re = curl_exec ($ch);
            curl_close ($ch);
            exit (json_encode (array ('code' => 0)));
        } else {
            $newurl = (($GLOBALS['options']->rewrite) ? $GLOBALS['options']->siteUrl : $GLOBALS['options']->siteUrl . 'index.php/') . 'action/CommentEdit?do=CallBack';
            $ret = json_decode (Bootstrap::fetch ('https://api.telegram.org/bot' . $_POST['token'] . '/setWebhook', [
                'url' => $newurl
            ], 'POST'), true);
    
            if ($ret['ok'] == true) {
                exit (json_encode (array ('code' => 0)));
            } else {
                exit (json_encode (array ('code' => -1, 'msg' => $ret['description'])));
            }
        }
    }
    
    /**
     * 解析 Telegram API 的请求
     *
     * @access public
     */
    public function CallBack () {
        if ($this->_cfg->mode != 0) {
            exit (json_encode (array ('code' => -1, 'msg' => '模式')));
        }
        $data = json_decode (file_get_contents ("php://input"), true);
        if (empty($data)) {
            exit (json_encode (array ('code' => -1, 'msg' => '参数为空')));
        }
        
        $reply_to_message = $data['message']['reply_to_message']['text'];
        if (isset($reply_to_message) && strpos($reply_to_message, "新评论") !== false) {
            $pattern = '/\*收到评论！\*\n\*文章 >>\* (.+?)\n\n\*(.+?)\*：\n(.+?)\n\n\*IP >>\* (.+?)\n\*User-Agent >>\* (.+?)\n\nCid=(\d+), Coid=(\d+)/';
            if (preg_match($pattern, $reply_to_message, $match)) {
                $CommentData = [
                    'cid' => $match[6],
                    'author' => $data['message']['chat']['username'],
                    'text' => $data['message']['text'],
                    'parent' => $match[7]
                ];
                $ret = $this->CommentAdd($CommentData);
                if ($ret['code'] == 0) {
                    $GLOBALS['telegramModel']->sendMessage($data['message']['chat']['id'], '回复成功');
                } else {
                    $GLOBALS['telegramModel']->sendMessage($data['message']['chat']['id'], $ret['msg']);
                }
            }
        }


        
        $callback_query = $data['callback_query'];
        if (isset ($callback_query) && isset($callback_query['data'])) {
            $callbackExplode = explode ('_', $callback_query['data']);
            if (isset ($callbackExplode[1])) {
                $coid = $callbackExplode[1];
                if ($callbackExplode[0] == 'delete') {
                    $CommentData = [
                        'coid' => $coid
                    ];
                    $ret = $this->CommentDel ($CommentData);
                    if ($ret['code'] == 0) {
                        $GLOBALS['telegramModel']->editMessage ($callback_query['message']['chat']['id'], $callback_query['message']['message_id'], '删除成功');
                    } else {
                        $GLOBALS['telegramModel']->editMessage ($callback_query['message']['chat']['id'], $callback_query['message']['message_id'], '删除失败');
                    }
                } else if ($callbackExplode[0] == 'spam') {
                    $CommentData = [
                        'coid' => $coid,
                        'status' => 'spam'
                    ];
                    $ret = $this->CommentMark ($CommentData);
                    if ($ret['code'] == 0) {
                        $button = json_encode (array (
                            'inline_keyboard' => array (
                                array (array (
                                    'text' => '通过评论',
                                    'callback_data' => 'approved_' . $coid
                                )),
                                array (array (
                                    'text' => '删除评论',
                                    'callback_data' => 'delete_' . $coid
                                ))
                            )
                        ));
                        $text = '#垃圾评论
' . $data['callback_query']['message']['text'];
                        $GLOBALS['telegramModel']->editMessage ($callback_query['message']['chat']['id'], $data['callback_query']['message']['message_id'], $text, $button);
                    } else {
                        $GLOBALS['telegramModel']->sendMessage ($callback_query['message']['chat']['id'], '标记垃圾评论失败');
                    }
                } else if ($callbackExplode[0] == 'approved') {
                    $CommentData = [
                        'coid' => $coid,
                        'status' => 'approved'
                    ];
                    $ret = $this->CommentMark ($CommentData);
                    if ($ret['code'] == 0) {
                        $button = json_encode (array (
                            'inline_keyboard' => array (
                                array (array (
                                    'text' => '垃圾评论',
                                    'callback_data' => 'spam_' . $coid
                                )),
                                array (array (
                                    'text' => '删除评论',
                                    'callback_data' => 'delete_' . $coid
                                ))
                            )
                        ));
                        $text = $data['callback_query']['message']['text'];
                        $text = str_replace('#垃圾评论', '', $text);
                        $GLOBALS['telegramModel']->editMessage ($callback_query['message']['chat']['id'], $data['callback_query']['message']['message_id'], $text, $button);
                    } else {
                       $GLOBALS['telegramModel']->sendMessage ($chat['id'], '通过评论失败');
                    }
                }
            }
        }
    }
    
    public function CommentAdd ($data = NULL) {
        if (empty($data)) {
            exit (json_encode (array ('code' => -1, 'msg' => '原地爆炸，螺旋升天 (A)')));
        }
        if ($this->_cfg->mode == 0) {
            $cid = $data['cid'];
            $author = $data['author'];
            $text = $data['text'];
            $parent = $data['parent'];
        } else {
            if (!isset($_POST['cid']) || !isset($_POST['author']) || !isset($_POST['text']) || !isset($_POST['parent'])) {
                exit (json_encode (array ('code' => -1, 'msg' => '原地爆炸，螺旋升天')));
            }
            $cid = $_POST['cid'];
            $author = $_POST['author'];
            $text = $_POST['text'];
            $parent = $_POST['parent'];
        }
        
        $ret = $this->userExists($author);
        if (!empty($ret)) {
            $comment = array(
                'cid'       =>  $cid,
                'author'    =>  $ret['screenName'],
                'authorId'  =>  $ret['uid'],
                'ownerId'   =>  '1',
                'mail'      =>  $ret['mail'],
                'url'       =>  $ret['url'],
                'agent'     =>  'TelegramBot',
                'text'      =>  $text,
                'parent'    =>  $parent
            );
            
            Typecho_Widget::widget('Widget_Abstract_Comments')->insert ($comment);

            $ContentInfo = $this->getContentInfo($cid);
            $CommentInfo = $this->getCommentInfo($parent);
            if (isset($ContentInfo) && isset($CommentInfo)) {
                $search  = array(
                    '{siteTitle}',
                    '{siteURL}',
                    '{title}',
                    '{author_p}',
                    '{author}',
                    '{permalink}',
                    '{text_p}',
                    '{text}',
                    '{time}'
                );
                $replace = array(
                    $GLOBALS['options']->title,
                    $GLOBALS['options']->siteUrl,
                    $ContentInfo['title'],
                    $CommentInfo['author'],
                    $ret['screenName'],
                    $GLOBALS['options']->siteUrl,
                    $CommentInfo['text'],
                    $text,
                    date('Y/m/d', $CommentInfo['created'])
                );
                $msgHtml = str_replace($search, $replace, file_get_contents(__TgNotify_PLUGIN_ROOT__ . '/template/guest.html'));
                Bootstrap::fetch(Plugin_Const::EMAIL_SENT_API, [
                    'email' => $CommentInfo['mail'],
                    'title' => '您在 ' . $ContentInfo['title'] . ' 的评论有了回复',
                    'content' => $msgHtml
                ]);   
            }
            if ($this->_cfg->mode == 0) {
                return array('code' => 0);
            } else {
                exit (json_encode(array('code' => 0)));
            }
        } else {
            if ($this->_cfg->mode == 0) {
                return array('code' => -2, 'msg' => '不允许此操作');
            } else {
                exit (json_encode(array('code' => -2, 'msg' => '不允许此操作')));
            }
        }
    }
    
    public function CommentDel ($data = NULL) {
        if (empty($data)) {
            exit (json_encode (array ('code' => -1, 'msg' => '原地爆炸，螺旋升天 (D)')));
        }
        if ($this->_cfg->mode == 0) {
            $coid = $data['coid'];
        } else {
            if (!isset($_POST['coid'])) {
                exit (json_encode (array ('code' => -1, 'msg' => '原地爆炸，螺旋升天')));
            }
            $coid = $_POST['coid'];
        }
        $comment = $this->_db->fetchRow($this->_db->select()->from('table.comments')->where('coid = ?', $coid)->limit(1));
        if (!empty($comment)) {
            $this->_db->query($this->_db->delete('table.comments')->where('coid = ?', $coid)); // 删除评论

            $this->_db->query($this->_db->update('table.contents')->expression('commentsNum', 'commentsNum - 1')->where('cid = ?', $comment['cid'])); // 更新评论数量   
        }
        
        if ($this->_cfg->mode == 0) {
            return array('code' => 0);
        } else {
            exit (json_encode(array('code' => 0)));
        }
    }
    
    public function CommentMark ($data = NULL) {
        if (empty($data)) {
            exit (json_encode (array ('code' => -1, 'msg' => '原地爆炸，螺旋升天 (M)')));
        }
        if ($this->_cfg->mode == 0) {
            $coid = $data['coid'];
            $status = $data['status'];
        } else {
            if (!isset($_POST['coid']) || !isset($_POST['status'])) {
                exit (json_encode (array ('code' => -1, 'msg' => '原地爆炸，螺旋升天')));
            }
            $coid = $_POST['coid'];
            $status = $_POST['status'];
        }
        
        $ret = $this->mark($coid, $status);
        if ($ret) {
            if ($this->_cfg->mode == 0) {
                return array('code' => 0);
            } else {
                exit (json_encode(array('code' => 0)));
            }
        } else {
            if ($this->_cfg->mode == 0) {
                return array('code' => -3, 'msg' => '标记失败');
            } else {
                exit (json_encode(array('code' => -3, 'msg' => '标记失败')));
            }
        }
    }
    
    /**
     * 判断是否为 HTTPS
     *
     * @access private
     * @return boolean
     */
    private function is_https () {
        if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
            return TRUE;
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            return TRUE;
        } elseif (!empty($_SERVER['HTTP_FRONT_END_HTTPS']) && strtolower($_SERVER['HTTP_FRONT_END_HTTPS']) !== 'off') {
            return TRUE;
        }
    
        return FALSE;
    }

    /**
     * 标记评论状态
     *
     * @access private
     * @param integer $coid 评论主键
     * @param string $status 状态
     *                 approved
     *                 waiting
     *                 spam
     * @return boolean
     */
    private function mark ($coid, $status)
    {
        $comment = $this->_db->fetchRow($this->_db->select()->from('table.comments')->where('coid = ?', $coid)->limit(1));
        if (!empty($comment)) {
            /** 不必更新的情况 */
            if ($status == $comment['status']) {
                return false;
            }
            
            $this->_db->query($this->_db->update('table.comments')->rows(array('status' => $status))->where('coid = ?', $coid));
            
            /** 更新相关内容的评论数 */
            if ('approved' == $comment['status'] && 'approved' != $status) {
                $this->_db->query($this->_db->update('table.contents')
                ->expression('commentsNum', 'commentsNum - 1')->where('cid = ? AND commentsNum > 0', $comment['cid']));
            } else if ('approved' != $comment['status'] && 'approved' == $status) {
                $this->_db->query($this->_db->update('table.contents')
                ->expression('commentsNum', 'commentsNum + 1')->where('cid = ?', $comment['cid']));
            }
            
            return true;
        }
        
        return false;
    }
    
    /**
     * 判断用户是否存在
     *
     * @access private
     * @param string $name 用户名
     * @return array
     */
    private function userExists($name)
    {
        $user = $this->_db->fetchRow($this->_db->select()
        ->from('table.users')
        ->where('name = ?', $name)->limit(1));

        return $user;
    }
    
    private function getCommentInfo ($coid)
    {
        $comment = $this->_db->fetchRow($this->_db->select()->from('table.comments')->where('coid = ?', $coid)->limit(1));
        
        return $comment;
    }
    
    private function getContentInfo ($cid)
    {
        $content = $this->_db->fetchRow($this->_db->select()->from('table.contents')->where('cid = ?', $cid)->limit(1));
        
        return $content;
    }
}
