<?php
// +----------------------------------------------------------------------
// | Created by PhpStorm.©️
// +----------------------------------------------------------------------
// | User: 程立弘
// +----------------------------------------------------------------------
// | Date: 2018/9/29 14:44
// +----------------------------------------------------------------------
// | Author: 程立弘 <1019759208@qq.com>
// +----------------------------------------------------------------------
namespace Lsclh\Wxpay;
//小程序与公众号微信支付封装
use EasySwoole\Http\Request;
use EasySwoole\Curl\Request as Curl;
use EasySwoole\Http\Response;
use EasySwoole\Utility\Random;

class Wxpay{

    /**
     * 默认支付参数配置,可以在这里配置,也可以在初始化的时候,统一传入参数
     * @var array
     */
    private $config = [];
    public $error_msg = '';
    private $request = null;
    private $response = null;

    public function __construct(Request $request,Response $response){
        $data['appid'] = ''; //appid
        $data['mch_id'] = ''; //商户号
        $data['pay_apikey'] = ''; //密钥
        $data['api_cert'] = '';//apiclient_cert.pem证书
        $data['api_key'] = '';//apiclient_key.pem证书
        $this->request = $request;
        $this->response = $response;
        $this->config = $data;
        
        $response->withHeader('Content-type', 'text/html;charset=UTF-8');
    }

    /**
     * 使用 $this->name=$value    配置参数
     * @param  string $name     配置名称
     * @param  string $value    配置值
     */
    public function __set($name,$value){
        if(isset($this->config[$name])) {
            $this->config[$name] = $value;
        }
    }

    /**
     * 使用 $this->name 获取配置
     * @param  string $name 配置名称
     * @return multitype    配置值
     */
    public function __get($name) {
        return $this->config[$name];
    }

    public function __isset($name){
        return isset($this->config[$name]);
    }

    //----------------------------------------------------------重点看这里---------------------------------------------------------
    /**
     * 微信支付请求接口(POST)
     * @param string $openid    openid
     * @param string $total_fee 金额 元
     * @param string $body      商品简单描述
     * @param string $order_sn  订单编号
     * @param string $url  成功后的回调
     * @return  json的数据
     */
    public function wxpay($openid,$total_fee,$body,$order_sn,$url){
        $config = $this->config;
        //统一下单参数构造
        $unifiedorder = [
            'appid'         => $config['appid'],
            'mch_id'        => $config['mch_id'],
            'nonce_str'     => $this->getNonceStr(),
            'body'          => $body,
            'out_trade_no'  => $order_sn,
            'total_fee'     => $total_fee * 100,
            'spbill_create_ip'  => $this->getip(),
            'notify_url'    => $url,
            'trade_type'    => 'JSAPI',
            'openid'        => $openid
        ];
        $unifiedorder['sign'] = $this->makeSign($unifiedorder);
        //请求数据,统一下单
        $xmldata = $this->array2xml($unifiedorder);
        $url = 'https://api.mch.weixin.qq.com/pay/unifiedorder';
        $res = $this->curl_post_ssl($url, $xmldata);
        if(!$res){
            $this->error_msg = '连接服务失败';
            return false;
        }

        $content = $this->xml2array($res);

        /**
         * 通信标识验证
         */
        if(strval($content['return_code']) == 'FAIL'){
            $this->error_msg = $content['return_msg'];
            return false;
        }

        /**
         * 交易标识验证
         */
        if(strval($content['result_code']) == 'FAIL'){
            $this->error_msg = '错误代码'.$content['err_code'].',描述:'.$content['err_code_des'];
            return false;
        }
        //$content为内容

        $time = time();
        settype($time, "string");       //jsapi支付界面,时间戳必须为字符串格式
        $resdata = [
            'appId'         => strval($content['appid']),
            'nonceStr'      => strval($content['nonce_str']),
            'package'       => 'prepay_id='.strval($content['prepay_id']),
            'signType'      => 'MD5',
            'timeStamp'     => $time
        ];
        $resdata['paySign'] = $this->makeSign($resdata);

        return $resdata;
    }

    /**
     * 微信退款(POST)
     * @param string(28) $transaction_id    在微信支付的时候,微信服务器生成的订单流水号,在支付通知中有返回
     * @param string $out_refund_no         自己系统的退款单号
     * @param string $total_fee             微信支付的时候支付的总金额(单位:分)
     * @param string $refund_fee            此次要退款金额(单位:分)
     * @return string                       xml格式的数据
     */
    public function refund($transaction_id,$out_refund_no,$total_fee,$refund_fee){
        $config = $this->config;
        //退款参数
        $refundorder = [
            'appid'         => $config['appid'],
            'mch_id'        => $config['mch_id'],
            'nonce_str'     => $this->getNonceStr(),
            'transaction_id'=> $transaction_id,
            'out_refund_no' => $out_refund_no,
            'total_fee'     => (int)($total_fee * 100),
            'refund_fee'    => (int)($refund_fee * 100)
        ];
        $refundorder['sign'] = $this->makeSign($refundorder);



        //请求数据,进行退款
        $xmldata = $this->array2xml($refundorder);

        $url = 'https://api.mch.weixin.qq.com/secapi/pay/refund';


        $res = $this->curl_post_ssl($url, $xmldata);
        if(!$res){
            $this->error_msg = '连接服务器失败';
            return false;
        }

        // 这句file_put_contents是用来查看服务器返回的结果 测试完可以删除了
        //file_put_contents('./log3.txt',$res,FILE_APPEND);

        $content = $this->xml2array($res);

//        dump($content);die;

        /**
         * 通信标识验证
         */
        if(strval($content['return_code']) == 'FAIL'){
            $this->error_msg = $content['return_msg'];
            return false;
        }
        /**
         * 交易标识验证
         */
        if(strval($content['result_code']) == 'FAIL'){
            $this->error_msg = '错误代码:'.$content['err_code'].',描述:'.$content['err_code_des'];
            return false;
        }
        return $content;
    }


    /**
     * [sendMoney 企业付款到零钱]
     * @param  number $amount     [发送的金额（单位分）目前发送金额不能少于1元]
     * @param  string $re_openid  [发送人的 openid]
     * @param  string $desc       [企业付款描述信息 (必填)]
     * @param  string $check_name [收款用户姓名 (选填)]
     * @return array             [array]
     */
    public function transfer($amount,$re_openid,$desc='测试',$check_name=''){

            if($amount < 100 || $amount > 500000){
                $this->error_msg = '提现金额有误,必须为1元到5000元之间';
                return false;
            }


            $config = $this->config;

            $data=array(
                'mch_appid'=>$config['appid'],//商户账号appid
                'mchid'=> $config['mch_id'],//商户号
                'nonce_str'=>$this->getNonceStr(),//随机字符串
                'partner_trade_no'=>date('YmdHis').rand(1000, 9999),//商户订单号
                'openid'=> $re_openid,//用户openid
                'check_name'=>'NO_CHECK',//校验用户姓名选项:不校验真实姓名
                'amount'=>$amount,//金额，单位分
                'desc'=> $desc,//企业付款描述信息
                'spbill_create_ip'=> $this->getip(),//Ip地址
            );
            $data['sign'] = $this->makeSign($data);


            $xml=$this->array2xml($data);

            $url='https://api.mch.weixin.qq.com/mmpaymkttransfers/promotion/transfers'; //调用接口
            $res=$this->curl_post_ssl($url,$xml);

            $result = $this->xml2array($res);
            if($result['return_code'] != 'SUCCESS'){
                $this->error_msg = $result['return_msg'];
                return false;
            }

            if ($result['result_code'] !='SUCCESS'){
                $this->error_msg = '错误代码:'.$result['err_code'].',描述:'.$result['err_code_des'];
                return false;
            }

            return [
                'partner_trade_no' => $result['partner_trade_no'],//商户订单号
                'payment_no'=>$result['payment_no'],//  企业付款成功，返回的微信付款单号
                'payment_time'=>$result['payment_time'],//企业付款成功时间
            ];





            //返回来的结果是xml，最后转换成数组
            /*
            array(9) {
              ["return_code"]=>
              string(7) "SUCCESS"
              ["return_msg"]=>
              array(0) {
              }
              ["mch_appid"]=>
              string(18) "wx57676786465544b2a5"
              ["mchid"]=>
              string(10) "143345612"
              ["nonce_str"]=>
              string(32) "iw6TtHdOySMAfS81qcnqXojwUMn8l8mY"
              ["result_code"]=>
              string(7) "SUCCESS"
              ["partner_trade_no"]=>
              string(18) "201807011410504098"
              ["payment_no"]=>
              string(28) "1000018301201807019357038738"
              ["payment_time"]=>
              string(19) "2018-07-01 14:56:35"
            }
            */
    }

    /**
     * 用于成功支付后的回调接口处验证微信签名信息通过后在进行操作
     * @return array|bool 返回false说明是假的攻击性回调调用 数组则是真的微信服务器数据
     */
    public function notify(){

        $xml = $this->request->getBody();        //获取微信支付服务器返回的数据

        //将服务器返回的XML数据转化为数组
        $data = $this->xml2array($xml);
        // 保存微信服务器返回的签名sign
        $data_sign = $data['sign'];
        // sign不参与签名算法
        unset($data['sign']);
        $sign = $this->makeSign($data);

        // 判断签名是否正确  判断支付状态
        if ( ($sign===$data_sign) && ($data['return_code']=='SUCCESS') && ($data['result_code']=='SUCCESS') ) {
            $result = $data;

//            //获取服务器返回的数据
//            $order_sn = $data['out_trade_no'];            //订单单号
//            $openid = $data['openid'];                    //付款人openID
//            $total_fee = $data['total_fee'];          //付款金额
//            $transaction_id = $data['transaction_id'];    //微信支付流水号
//

        }else{
            $result = false;
        }
        // 返回状态给微信服务器
        if ($result) {
            $this->response->write('<xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>');
        }else{
            $this->error_msg = '签名失败';
            $this->response->write('<xml><return_code><![CDATA[FAIL]]></return_code><return_msg><![CDATA[签名失败]]></return_msg></xml>');
            $this->response->end();
        }
        return $result;
    }

    public function getError(){
        return $this->error_msg;
    }


//-------------------------------------------------------------------------------------------------------------------------------

//---------------------------------------------------------------用到的函数------------------------------------------------------
    /**
     * 将一个数组转换为 XML 结构的字符串
     * @param array $arr 要转换的数组
     * @param int $level 节点层级, 1 为 Root.
     * @return string XML 结构的字符串
     */
    protected function array2xml($arr, $level = 1) {
        $s = $level == 1 ? '<xml>' : '';
        foreach($arr as $tagname => $value) {
            if (is_numeric($tagname)) {
                $tagname = $value['TagName'];
                unset($value['TagName']);
            }
            if(!is_array($value)) {
                $s .= "<{$tagname}>".(!is_numeric($value) ? '<![CDATA[' : '').$value.(!is_numeric($value) ? ']]>' : '')."</{$tagname}>";
            } else {
                $s .= "<{$tagname}>" . $this->array2xml($value, $level + 1)."</{$tagname}>";
            }
        }
        $s = preg_replace("/([\x01-\x08\x0b-\x0c\x0e-\x1f])+/", ' ', $s);
        return $level == 1 ? $s.'</xml>' : $s;
    }

    /**
     * 将xml转为array
     * @param  string   $xml xml字符串
     * @return array    转换得到的数组
     */
    protected function xml2array($xml){
        //禁止引用外部xml实体
        libxml_disable_entity_loader(true);
        $result= json_decode(json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
        return $result;
    }

    /**
     *
     * 产生随机字符串，不长于32位
     * @param int $length
     * @return 产生的随机字符串
     */
    protected function getNonceStr($length = 32) {
        return Random::character($length);
    }

    /**
     * 生成签名
     * @return 签名
     */
    protected function makeSign($data){
        //获取微信支付秘钥
        $key = $this->config['pay_apikey'];
        // 去空
        $data=array_filter($data);
        //签名步骤一：按字典序排序参数
        ksort($data);
        $string_a=http_build_query($data);
        $string_a=urldecode($string_a);
        //签名步骤二：在string后加入KEY
        $string_sign_temp=$string_a."&key=".$key;
        //签名步骤三：MD5加密
        $sign = md5($string_sign_temp);
        // 签名步骤四：所有字符转为大写
        $result=strtoupper($sign);
        return $result;
    }

    /**
     * 获取IP地址
     * @return [String] [ip地址]
     */
    protected function getip() {
        return $this->request->getHeader('host');
    }

    /**
     * 微信支付发起请求
     */
    protected function curl_post_ssl($url, $xmldata, $second=30,$aHeader=array()){
        $config = $this->config;
        $request = (new Curl($url))->setUserOpt([
            CURLOPT_TIMEOUT=>$second,
            CURLOPT_RETURNTRANSFER=>1,
            CURLOPT_URL=>$url,
            CURLOPT_SSL_VERIFYPEER=>false,
            CURLOPT_SSL_VERIFYHOST=>0,
            CURLOPT_SSLVERSION=>1,
            CURLOPT_SSLCERTTYPE=>'PEM',         //默认格式为PEM，可以注释
            CURLOPT_SSLCERT=>$config['api_cert'],
            CURLOPT_SSLKEYTYPE=>'PEM',        //默认格式为PEM，可以注释
            CURLOPT_SSLKEY=>$config['api_key'],
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>$xmldata
        ]); //获取对象并添加 opt

        //是否添加header
        if( count($aHeader) >= 1 ) $request->setUserOpt([CURLOPT_HTTPHEADER=>$aHeader]);

        //执行
        $response = $request->exec();
        $content = $response->getBody();
        if($content){
            return $content;
        }else {
            $this->error_msg = 'call faild, errorCode:'.$response->getErrorNo().',msg:'.$response->getError();
            return false;
        }
    }







//  //测试支付
//  public function paytest(){
//      $openid = 'ovprvtzBZaWXnZUadwgexOLNc93M';
//      $total_fee = 0.01;
//      $body = '微信支付';
//      $order_sn = date('YmdHis').mt_rand(1000,9999);
//      $res = $this->wxpay($openid,$total_fee,$body,$order_sn);
//      return $res;
//  }
//
//  //测试退款
//  public function refundtest(){
//      $transaction_id = '4200000126201807135608543773';
//      $out_refund_no = '3323455121';
//      $total_fee = 0.01;
//      $refund_fee = 0.01;
//      $res = $this->refund($transaction_id,$out_refund_no,$total_fee,$refund_fee);
//      return $res;
//  }
//
//  //支付成功回调延签用法
//    //此方法是我们在支付时传给的服务器的用于接收回调的路由地址
//  public function successPaytest(){
//        //验证请求是不是微信发的 作为安全验证
//        $rel = $this->notify();
//        if(!$rel) return;
//
//        //在此处写逻辑支付成功后的逻辑
//        //$rel里有微信返回的数据
//        //$rel['transaction_id']用于退款的微信的账单号
//        //$rel['total_fee']这个是以    (分)(几分钱)    为单位的支付的金额
//        //$rel['out_trade_no']支付时我们传给微信的单号

//    }




}

/*===============================================使用方法=======================================================


//使用方法一:
    * 配置好自己的参数,注意这里的两个证书路径得根据你自己的项目证书路径来写,同时存放证书的目录要开放可读权限
    * 单纯的支付不需要证书 , 退款的时候需要证书
    $config = array(
        'appid'     => 'wx123456789876',
        'mch_id'        => '123456789',
        'pay_apikey'    => '123456789876123456789876123456789876',
        'api_cert'      => getcwd().'/cert/apiclient_cert.pem',
        'api_key'       => getcwd().'/cert/apiclient_key.pem'
    );

    $wxpay = new WxPay($config);                                                //初始化类(同时传递参数)
    $data = $wxpay->wxpay($openid,$total_fee,$body,$order_sn);                  //微信支付,将返回值$data(json格式)返回给页面,进行JSAPI支付
    $wxpay->refund($transaction_id,$out_refund_no,$total_fee,$refund_fee);      //微信退款


//使用方法二:
    $wxpay = new WxPay();                                           //初始化类

    $wxpay->appid       = 'wx123456789876';                         //配置参数
    $wxpay->mch_id      = '123456789';
    $wxpay->pay_apikey  = '123456789876123456789876123456789876';
    $wxpay->api_cert        = getcwd().'/cert/apiclient_cert.pem';
    $wxpay->api_key         = getcwd().'/cert/apiclient_key.pem';

    $data = $wxpay->wxpay($openid,$total_fee,$body,$order_sn);                  //微信支付,将返回值$data(json格式)返回给页面,进行JSAPI支付
    $wxpay->refund($transaction_id,$out_refund_no,$total_fee,$refund_fee);      //微信退款

================================================================================================================*/