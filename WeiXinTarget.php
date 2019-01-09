<?php
namespace common\core\log;
use common\core\Common;
use Yii;
use yii\base\InvalidConfigException;
use yii\log\Target;

/**
 * EmailTarget sends selected log messages to the specified email addresses.
 *
 * You may configure the email to be sent by setting the [[message]] property, through which
 * you can set the target email addresses, subject, etc.:
 *
 * ```php
 * 'components' => [
 *     'log' => [
 *          'targets' => [
 *              [
 *                  'class' => 'common\core\log\WeiXinTarget',
 *                  'corp_id' => 'corp_id',
 *                  'message_app_id' => 'message_app_id',
 *                  'message_app_secret' => 'message_app_secret',
 *                  'toparty' => 'toparty',
 *                  'levels' => ['error', 'warning'],
 *              ],
 *          ],
 *     ],
 * ],
 * ```
 *
 * In the above `mailer` is ID of the component that sends email and should be already configured.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class WeiXinTarget extends Target
{
    public $corp_id = ''; // 企业的id，在管理端->"我的企业" 可以看到
    public $message_app_id = ''; // 某个自建应用的id及secret, 在管理端 -> 企业应用 -> 自建应用, 点进相应应用可以看到
    public $message_app_secret = '';
    public $toparty = ''; // 部门ID列表，多个接收者用‘|’分隔，最多支持100个。当touser为@all时忽略本参数

    /**
     * {@inheritdoc}
     */
    public function init()
    {
        parent::init();

        if (empty($this->corp_id)) {
            throw new InvalidConfigException('The "corp_id" option must be set for WeiXinTarget::message.');
        }

        if (empty($this->message_app_id)) {
            throw new InvalidConfigException('The "message_app_id" option must be set for WeiXinTarget::message.');
        }

        if (empty($this->message_app_secret)) {
            throw new InvalidConfigException('The "message_app_secret" option must be set for WeiXinTarget::message.');
        }

        if (empty($this->toparty)) {
            throw new InvalidConfigException('The "toparty" option must be set for WeiXinTarget::message.');
        }
    }

    /**
     * Sends log messages to specified email addresses.
     * Starting from version 2.0.14, this method throws LogRuntimeException in case the log can not be exported.
     * @throws LogRuntimeException
     */
    public function export()
    {
        $text = implode("\n", array_map([$this, 'formatMessage'], $this->messages)) . "\n";
        $this->sendMessageByParty($text);
    }

    /**
     * 获取企业凭证
     * @return string
     */
    public function getToken()
    {
        $url = 'https://qyapi.weixin.qq.com/cgi-bin/gettoken';
        $data = [
            'corpid' => $this->corp_id,
            'corpsecret' => $this->message_app_secret,
        ];

        $key = 'provider_token_' . $this->message_app_id;
        $providerSecret = Yii::$app->redis->get($key);

        if (!empty($providerSecret)) {
            return $providerSecret;
        }

        $result = Yii::$app->curl->get($url, $data);

        $result = json_decode($result, true);
        if (isset($result['access_token'])) {
            $providerSecret = $result['access_token'];
            Yii::$app->redis->setex($key, $result['expires_in'] - 1200, $providerSecret);
            return $providerSecret;
        } else {
            return '';
        }
    }

    /**
     * 通过部门id发送消息
     * @param string $msg
     * @return mixed
     */
    public function sendMessageByParty($msg='')
    {
        $url = 'https://qyapi.weixin.qq.com/cgi-bin/message/send';
        $query = [
            'access_token' => $this->getToken(),
        ];

        $data = [
            'agentid' => $this->message_app_id,
            'toparty' => $this->toparty,
            'msgtype' => 'text',
            'text' => [
                'content' => $msg
            ]
        ];

        $result = Yii::$app->curl->post($url, json_encode($data), $query);
        $result = json_decode($result, true);
        return $result;
    }
}
