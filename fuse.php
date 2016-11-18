<?php

/**
 * Class fuseStorage
 *   熔断存储类
 *
 * @uses apc_store() apc_inc() apc_fetch()
 */
class fuseStorage{

    public function set($key , $value , $expireSeconds)
    {
        if($key){
            return apc_store($key , $value , $expireSeconds);
        }
        return true;
    }
    public function inc($key , $incValue){
        return apc_inc($key , $incValue);
    }
    public function get($key){
        return apc_fetch($key);
    }
    public function mGet($keyList)
    {
        return apc_fetch($keyList);
    }
}
class fuseConditionConfig{

    public static function get($key){
        return self::_getConfig($key);
    }
    private static function _getConfig($key)
    {
        $arrDefaultTpl = array(
            "value" => 0.5, //阈值 , 配合valueType使用
            "valueType" => 0, //0:累计失败率达到0<n<1,1:累计失败次数达到n;2:累计请求数达到n(qps限制)
            "durationSeconds" => 60 , //累计周期(默认60s , 60秒)
            "callbackClass" => "", //回调类名字 , 不为空则回调请求:$callbackClass::$callbackFunction($key , $fuseConditonConfig , $curValue);
            "callbackFunction" => "",//指定触发条件后的回调函数名字,为空则直接抛出异常
            "callbackPercent" => 1, //达到条件后触发回调的概率 , 默认为 1 == 100% , >= 1认为是100%触发
            "waitingSeconds" => 3, //当达到条件后 , 需要等待的恢复时间 , 默认为 3 (3秒)
        );
        $arrConfigList = array(
            "sendGift" => array( //conditionKey
                "value" => 0.5, //默认为0.5 , 阈值 , 配合valueType使用
                "valueType" => 0, //默认值0 (0:累计失败率达到0<n<1,1:累计失败次数达到n;2:累计请求数达到n(qps限制))
                "durationSeconds" => 60 , //累计周期(默认60s , 60秒)
                "callbackClass" => "", //默认为空 , 回调类名字 , 不为空则回调请求:$callbackClass::$callbackFunction($key , $fuseConditonConfig , $curValue);
                "callbackFunction" => "",//默认为空 , 指定触发条件后的回调函数名字,为空则直接抛出异常
                "callbackPercent" => 1, //默认为1 , 达到条件后触发回调的概率 , 默认为 1 == 100% , >= 1认为是100%触发
                "waitingSeconds" => 3, //默认为3 , 当达到条件后 , 需要等待的恢复时间 , 默认为 3 (3秒)
            ),
        );
        if($arrConfigList[$key]) {
            $arr = array_merge($arrDefaultTpl , $arrConfigList[$key]);
        } else {
            $arr = array();
        }

        return $arr ;
    }
}

/**
 * Class fuseMath
 *
 * @uses mt_rand()
 */
class fuseMath{
    const RESULT_TYPE_SUCCESS_KEY="_success";
    const RESULT_TYPE_FAILE_KEY="_faile";
    const BLOCK_KEY="_block";
    private $objFuseStorage = null;
    private $arrResultTypeList = array(
        0 => "success",
        1 => "fail",
    );
    public function fuseMath($fuseStorage){
        if ($fuseStorage instanceof fuseStorage) {
            $this->objFuseStorage = $fuseStorage;
            return true;
        }
    }

    /**
     * letMeRequest
     *
     * @param $key
     * @param $conditionKey
     * @return bool
     * @throws Exception
     */
    public function letMeRequest($key , $conditionKey){
        if($key != "") {

            $curCondition           = fuseConditionConfig::get($conditionKey);
            if(!$curCondition)
                return false;

            $arrKeys                = array(
                $key.self::BLOCK_KEY,
                $key.self::RESULT_TYPE_SUCCESS_KEY,
                $key.self::RESULT_TYPE_FAILE_KEY
            );

            $valueList          = $this->objFuseStorage->mGet($arrKeys);
            $curBlockStatus     = isset($valueList[$key.self::BLOCK_KEY]) ? $valueList[$key.self::BLOCK_KEY] : 0;

            if($curBlockStatus) {
                $isNeedSetBlockStatus   = false;
                $this->_fuse($key , $curCondition , $valueList , $isNeedSetBlockStatus);
                return false; //达到熔断条件了
            }
        }

        return true;
    }

    /**
     * incrementRequestResult
     *       累计请求结果
     *
     * @param $key              关键字(class_function_args)
     * @param $conditionKey     指定条件熔断配置key
     * @param $resultType       请求结果类型 (0:业务正常[成功],1:业务异常)
     * @param $incrementValue   累加数值
     * @return bool
     * @throws Exception
     */
    public function incrementRequestResult($key , $conditionKey , $resultType , $incrementValue = 1){
        if(!isset($this->arrResultTypeList[$resultType])) {
            return false;
        }
        $curCondition   = fuseConditionConfig::get($conditionKey);

        if(!$curCondition)
            return false;

        $arrKeys        = array(
            $key.self::BLOCK_KEY,
            $key.self::RESULT_TYPE_SUCCESS_KEY,
            $key.self::RESULT_TYPE_FAILE_KEY
        );

        $valueList          = $this->objFuseStorage->mGet($arrKeys);

        $curBlockStatus     = isset($valueList[$key.self::BLOCK_KEY]) ? $valueList[$key.self::BLOCK_KEY] : 0;

        if($curBlockStatus) {  //达到block 状态的时候不用累计
            return false;
        }

        //累加数据 Start
        switch($resultType){
            case 1: //faile
                $curKey    = $key.self::RESULT_TYPE_FAILE_KEY;
                break;
            case 0: //success
            default: //success
                $curKey    = $key.self::RESULT_TYPE_SUCCESS_KEY;
        }
        $curValue       = isset($valueList[$curKey]) ? $valueList[$curKey] : null;
        if(is_numeric($curValue)){
            $incrementResult = $this->objFuseStorage->inc($curKey , $incrementValue);
            $valueList[$curKey] += $incrementValue; //同步修改本地取出的数据
        }else{
            $durationSeconds = isset($curCondition['durationSeconds']) && int($curCondition['durationSeconds']) > 0 ? $curCondition['durationSeconds'] : 60; //保留时长
            $incrementResult = $this->objFuseStorage->set($curKey , $incrementValue , $durationSeconds);
            $valueList[$curKey] = $incrementValue; //同步修改本地取出的数据
        }

        //累加数据 End


        if($incrementResult){
            //如果达到block条件 , 设置block
            $isNeedSetBlockStatus = true;
            $this->_fuse($key , $curCondition , $valueList , $isNeedSetBlockStatus);
        }

        return $incrementResult;
    }

    /**
     * 计算是否达到熔断条件 , 并触发熔断事件
     *
     * @param $key
     * @param $curCondition
     * @param $valueList
     * @param bool|false $isNeedSetBlockStatus  是否要写入熔断状态数据
     * @return bool
     * @throws Exception
     */
    private function _fuse($key , $curCondition , $valueList , $isNeedSetBlockStatus = false){
        if(!$curCondition) return false;

        $curValueSuccess = isset($valueList[$key . self::RESULT_TYPE_SUCCESS_KEY]) ? $valueList[$key . self::RESULT_TYPE_SUCCESS_KEY] : 0;
        $curValueFaile = isset($valueList[$key . self::RESULT_TYPE_FAILE_KEY]) ? $valueList[$key . self::RESULT_TYPE_FAILE_KEY] : 0;

        $settingValue = isset($curCondition['value']) ? $curCondition['value'] : 0.5;
        $settingValueType = isset($curCondition['valueType']) ? $curCondition['valueType'] : 0;

        switch ($settingValueType) { //(0:累计失败率达到0<n<1,1:累计失败次数达到n;2:累计请求数达到n(qps限制))
            case 0: //0:累计失败率达到0<n<1
                $curPercent = $curValueFaile / ($curValueSuccess + $curValueFaile);
                if ($curPercent >= $settingValue) {
                    $isPass = false;
                    $reasonString = "累计失败率达到[".($curPercent*100)."%/".($settingValue*100)."%]";
                }
                break;
            case 1://1:累计失败次数达到n
                if ($curValueFaile >= $settingValue) {
                    $isPass = false;
                    $reasonString="累计失败次数达到[".($curValueFaile)."/".($settingValue)."]";
                }
                break;
            case 2: //2:累计请求数达到n(qps限制)
                if ($curValueFaile + $curValueSuccess >= $settingValue) {
                    $isPass = false;
                    $reasonString="累计请求数达到[".($curValueFaile)."/".($settingValue)."](qps限制)";
                }
                break;
            default:
                $isPass = true;
        }

        if ($isNeedSetBlockStatus && $isPass == false) {//触发了熔断条件
            //设置熔断状态
            $waitingSeconds=isset($curCondition["waitingSeconds"])?$curCondition["waitingSeconds"]:3;
            $this->objFuseStorage->set($key.self::BLOCK_KEY , 1 , $waitingSeconds); //设置缓冲时间
        }

        //熔断回调判断
        $className = isset($curCondition['callbackClass']) ? $curCondition['callbackClass'] : "";
        $function = isset($curCondition['callbackFunction']) ? $curCondition['callbackFunction'] : "";
        $callbackPercent = isset($curCondition['callbackPercent']) ? $curCondition['callbackPercent'] : 1; //回调执行概率

        $isCallback = true;
        if($function == "") {
            $isCallback = false;
        }else if($callbackPercent > 0) {
            //超出1认为是100%触发
            if ($callbackPercent < 1) {
                $tmp = mt_rand(0, 100);
                if ($tmp / 100 < $callbackPercent) { //概率小于设定 , 不执行回调
                    $isCallback = false;
                }
            }
        }
        //没设定回调函数 or 触发回调函数概率小于0
        if($isCallback){
            try{
                if($className){
                    return $className::$function($key,$curValueSuccess,$curValueFaile,$curCondition,$reasonString);
                }else{
                    return $function($key,$curValueSuccess,$curValueFaile,$curCondition,$reasonString);
                }
            }catch(Exception $e) {
                //TODO:log
                //class function not exists or callback error
                throw $e;
            }
        }

        return $isPass;
    }
}
