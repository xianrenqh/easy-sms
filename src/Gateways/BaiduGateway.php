<?php

/*
 * This file is part of the overtrue/easy-sms.
 *
 * (c) overtrue <i@overtrue.me>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Overtrue\EasySms\Gateways;

use Overtrue\EasySms\Contracts\MessageInterface;
use Overtrue\EasySms\Contracts\PhoneNumberInterface;
use Overtrue\EasySms\Exceptions\GatewayErrorException;
use Overtrue\EasySms\Support\Config;
use Overtrue\EasySms\Traits\HasHttpRequest;

/**
 * Class BaiduGateway.
 *
 * @see https://cloud.baidu.com/doc/SMS/index.html
 */
class BaiduGateway extends Gateway
{
    use HasHttpRequest;

    public const ENDPOINT_HOST = 'smsv3.bj.baidubce.com';

    public const ENDPOINT_URI = '/api/v3/sendSms';

    public const BCE_AUTH_VERSION = 'bce-auth-v1';

    public const DEFAULT_EXPIRATION_IN_SECONDS = 1800; // 签名有效期默认1800秒

    public const SUCCESS_CODE = 1000;

    /**
     * Send message.
     *
     * @return array
     *
     * @throws GatewayErrorException ;
     */
    public function send(PhoneNumberInterface $to, MessageInterface $message, Config $config)
    {
        $params = [
            'signatureId' => $config->get('invoke_id'),
            'mobile' => $to->getNumber(),
            'template' => $message->getTemplate($this),
            'contentVar' => $message->getData($this),
        ];
        if (!empty($params['contentVar']['custom'])) {
            // 用户自定义参数，格式为字符串，状态回调时会回传该值
            $params['custom'] = $params['contentVar']['custom'];
            unset($params['contentVar']['custom']);
        }
        if (!empty($params['contentVar']['userExtId'])) {
            // 通道自定义扩展码，上行回调时会回传该值，其格式为纯数字串。默认为不开通，请求时无需设置该参数。如需开通请联系客服申请
            $params['userExtId'] = $params['contentVar']['userExtId'];
            unset($params['contentVar']['userExtId']);
        }

        $datetime = gmdate('Y-m-d\TH:i:s\Z');

        $headers = [
            'host' => self::ENDPOINT_HOST,
            'content-type' => 'application/json',
            'x-bce-date' => $datetime,
        ];
        // 获得需要签名的数据
        $signHeaders = $this->getHeadersToSign($headers, ['host', 'x-bce-date']);

        $headers['Authorization'] = $this->generateSign($signHeaders, $datetime, $config);

        $result = $this->request('post', self::buildEndpoint($config), ['headers' => $headers, 'json' => $params]);

        if (self::SUCCESS_CODE != $result['code']) {
            throw new GatewayErrorException($result['message'], $result['code'], $result);
        }

        return $result;
    }

    /**
     * Build endpoint url.
     *
     * @return string
     */
    protected function buildEndpoint(Config $config)
    {
        return 'http://'.$config->get('domain', self::ENDPOINT_HOST).self::ENDPOINT_URI;
    }

    /**
     * Generate Authorization header.
     *
     * @param int $datetime
     *
     * @return string
     */
    protected function generateSign(array $signHeaders, $datetime, Config $config)
    {
        // 生成 authString
        $authString = self::BCE_AUTH_VERSION.'/'.$config->get('ak').'/'
            .$datetime.'/'.self::DEFAULT_EXPIRATION_IN_SECONDS;

        // 使用 sk 和 authString 生成 signKey
        $signingKey = hash_hmac('sha256', $authString, $config->get('sk'));
        // 生成标准化 URI
        // 根据 RFC 3986，除了：1.大小写英文字符 2.阿拉伯数字 3.点'.'、波浪线'~'、减号'-'以及下划线'_' 以外都要编码
        $canonicalURI = str_replace('%2F', '/', rawurlencode(self::ENDPOINT_URI));

        // 生成标准化 QueryString
        $canonicalQueryString = ''; // 此 api 不需要此项。返回空字符串

        // 整理 headersToSign，以 ';' 号连接
        $signedHeaders = empty($signHeaders) ? '' : strtolower(trim(implode(';', array_keys($signHeaders))));

        // 生成标准化 header
        $canonicalHeader = $this->getCanonicalHeaders($signHeaders);

        // 组成标准请求串
        $canonicalRequest = "POST\n{$canonicalURI}\n{$canonicalQueryString}\n{$canonicalHeader}";

        // 使用 signKey 和标准请求串完成签名
        $signature = hash_hmac('sha256', $canonicalRequest, $signingKey);

        // 组成最终签名串
        return "{$authString}/{$signedHeaders}/{$signature}";
    }

    /**
     * 生成标准化 http 请求头串.
     *
     * @return string
     */
    protected function getCanonicalHeaders(array $headers)
    {
        $headerStrings = [];
        foreach ($headers as $name => $value) {
            // trim后再encode，之后使用':'号连接起来
            $headerStrings[] = rawurlencode(strtolower(trim($name))).':'.rawurlencode(trim($value));
        }

        sort($headerStrings);

        return implode("\n", $headerStrings);
    }

    /**
     * 根据 指定的 keys 过滤应该参与签名的 header.
     *
     * @return array
     */
    protected function getHeadersToSign(array $headers, array $keys)
    {
        return array_intersect_key($headers, array_flip($keys));
    }
}
