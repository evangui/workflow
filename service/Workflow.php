<?php
/**
 * 审批工作流核心服务类文件
 * 
 * Author: Evan Gui
 * Email: guiyj007@gmail.com
 * Date: 2016/10/26
 * Time: 11:45
 */
class WorkflowService
{
    const ROLE_SELLER = 1;
    const ROLE_ADMIN = 0;

    const TYPE_GOODS_COST = 0;
    const TYPE_CONGOODS_EDIT = 1;
    const TYPE_PLAGOODS_EDIT = 2;
    const TYPE_STOREFILE_EDIT = 3;
    const TYPE_COMMIS_RATE = 4;
    const TYPE_STORE_NEWCOMMIS = 5;
    const TYPE_BUSSINESS_CLASSADD = 7;
    const TYPE_GOODS_PUBLISH = 10;
    const TYPE_MANAGE_TYPE = 11;
    const TYPE_ORDER_BILL_EDIT = 12;

    const TYPE_CLASS_TAX = 51;
    const TYPE_GOODS_TAX = 52;
    const TYPE_STORE_JOIN = 53;

    /** @var  string 操作人员名称 */
    public $user;

    /** @var integer  操作人员账户类型 */
    public $role;

    /** @var  workflowModel */
    public $workflowModel;

    public $types = array(
        self::TYPE_GOODS_COST => 'goodsCost',
        self::TYPE_GOODS_PUBLISH => 'goodsPublish',
        self::TYPE_MANAGE_TYPE => 'manageType',
        self::TYPE_ORDER_BILL_EDIT => 'orderBillEdit',
        self::TYPE_CONGOODS_EDIT => 'constructGoodsEdit',
        self::TYPE_PLAGOODS_EDIT => 'platformGoodsEdit',
        self::TYPE_CLASS_TAX => 'classTax',
        self::TYPE_GOODS_TAX => 'goodsTax',
        self::TYPE_STOREFILE_EDIT => 'storeFileEdit',
        self::TYPE_COMMIS_RATE => 'commisRate',
    	self::TYPE_STORE_JOIN => 'storeJoin' ,
        self::TYPE_STORE_NEWCOMMIS=>'storeAddCommisRate',
        self::TYPE_BUSSINESS_CLASSADD=>'businessAddClass',
    );

    /** @var  WorkflowHandler */
    private $_handler;

    /** @var array 工作流配置 */
    private $_config;

    /** @var  string 当前操作人员角色组 */
    private $_group;

    private $_attributes;

    /** @var  array 审核流程模型数据 */
    private $_model;

    /** @var  integer 审核流程类型 */
    private $_type;

    public function __construct()
    {
        $this->workflowModel = Model('workflow');
    }

    /**
	 * 初始化配置
	 */
    public function init($model = null, $user = null, $group = null, $role = 0)
    {
        $this->setModel($model);
        $this->setGroup($group);
        $this->user = $user;
        $this->role = $role;

    }

    /**
     * @param $post
     * @return array
     */
    public function response($post)
    {
        if ($this->getGroup() != $this->getStage()) return array('state' => false, 'msg' => '对不起！您无权审核!');
        try {
            $response = $this->getHandler()->response($post, $this);
            if (is_array($response)) {
                if (isset($response['state']) && isset($response['msg'])) return $response;
                $response['state'] = true;
            } else if (is_string($response)) {
                $response = array('state' => false, 'msg' => $response);
            } else if (is_bool($response)) {
                $response = $response ? array('state' => $response) : array('state' => $response, 'msg' => '有错误发生！');
            }
            return $response;
        } catch (Exception $e) {
            return array('state' => false, 'msg' => $e->getMessage());
        }
    }

    /**
	 * 启动审核
	 *
     * @param array $newValue
     * @param array $oldValue
     * @param integer $type
     * @param mixed $key
     * @param $cancelable boolean
     * @param $title 标题
     * @return bool
     * @throws Exception
     */
    public function launch($type = null, $key, $newValue = array(), $oldValue = array(), $cancelable = false , $title = null)
    {
        if ($type !== null) $this->setTpe($type);
        if(!in_array($this->getGroup(),(array)$this->getHandler()->getStartGroup()))
            throw new Exception('您所在的用户组不能启动该审批流程！');
        foreach ($newValue as $k=>$v){
            if($oldValue[$k] == $v) unset($newValue[$k]);
        }
        //if(empty($newValue)) throw new Exception('您并没有修改任何值！');
        $workflowModel = $this->workflowModel;
        $workflowModel->beginTransaction();
        $model = $workflowModel->getWorkflowInfo(array(
            'status' => $workflowModel::STATUS_PROCESSING,
            'type' => $this->getHandler()->getId(),
            'model_id' => $key,
        ));
        if (!empty($model)) {
            if ($cancelable) {
                $this->setModel($model);
                $this->cancel('重新发起');
                $model = array();
            } else {
                throw new Exception('已经有审批流程正在进行，您不能再次提请审批！');
            }
        }
        if (empty($model)) {
            $model = array(
                'title' => $title?$title:$this->getHandler()->getTitle($key),
                'model' => $this->getHandler()->getModelName(),
                'model_id' => $key,
                'stage' => $this->getGroup(),
                'type' => $this->getHandler()->getId(),
                'new_value' => $newValue,
                'old_value' => $oldValue,
                'reference' => $this->getHandler()->getReference($key),
                'role' => $this->role,
                'user' => $this->user,
                'timeout_at' => TIMESTAMP + 60,
            );
            $model['id'] = $workflowModel->addWorkflow($model);
        }
        $this->setModel($model);
        $post = $_POST;
        if(!isset($post['opinion'])) $post['opinion'] = 1;
        if(!isset($post['message'])) $post['message'] = '发起审批';
        try {
            $res = $this->getHandler()->response($post, $this);//approve('发起审批');
            if ($res !== true) {
                $workflowModel->rollback();
                return false;
            }
            $workflowModel->commit();
            return true;
        } catch (Exception $e) {
            $workflowModel->rollback();
            throw new Exception($e->getMessage());
        }
    }

    /**
	 * 同意审核
	 *
	 * @param string $message	拒绝说明
     * @param binary $attachment 附件
     * @param control $type  拒绝类型
     * @throws Exception
     */
    public function approve($message = null, $attachment = null,$control=null)
    {
        $model = $this->getModel();
        $workflowModel = $this->workflowModel;
        if ($this->getGroup() !== $model['stage']) throw new Exception('您所在的用户组不允许进行此操作');
        $this->workflowModel->beginTransaction();
        $approveConfig = $this->getApproveConfig();
        if ($approveConfig instanceof Closure) {
            $approveConfig = call_user_func($approveConfig, $model, $control);
        }
        if ($approveConfig == '') {
            if (!$this->finish() || !$this->updateWorkflow('closed', $workflowModel::STATUS_FINISHED)) {
                $this->workflowModel->rollback();
                return false;
            }
        } else if ($approveConfig == 'closed') {
            if (!$this->updateWorkflow('closed', $workflowModel::STATUS_FINISHED)) {
                $this->workflowModel->rollback();
                return false;
            }
        } else {
            if (!$this->updateWorkflow($approveConfig, $workflowModel::STATUS_PROCESSING)) {
                $this->workflowModel->rollback();
                return false;
            }
        }
        if (!$this->log(1, $message, $attachment)) {
            $this->workflowModel->rollback();
            return false;
        }

        $this->workflowModel->commit();
        return true;
    }

	/**
	 * 拒绝审核
	 *
	 * @param string $message	拒绝说明
     * @param binary $attachment 附件
     * @param control $type  拒绝类型
     * @throws Exception
     */
	 */
    public function reject($message = null, $attachment = null,$control=null)
    {
        $model = $this->getModel();
        $workflowModel = $this->workflowModel;
        if ($this->getGroup() !== $model['stage']) throw new Exception('您所在的用户组不允许进行此操作');
        $rejectConfig = $this->getRejectConfig();
        if ($rejectConfig instanceof Closure) {
        	$model['message'] = $message ;
            $rejectConfig = call_user_func($rejectConfig, $model, $control);
        }
        if ($rejectConfig == '') {
            $rejectConfig = $this->getStartGroup();
        }
        if (!$this->updateWorkflow($rejectConfig, $workflowModel::STATUS_PROCESSING) || !$this->log(0, $message, $attachment)) {
            $this->workflowModel->rollback();
            return false;
        }
        $this->workflowModel->commit();
        return true;
    }

    public function cancel($message)
    {
        $workflowModel = $this->workflowModel;
        if (!$this->updateWorkflow('canceled', $workflowModel::STATUS_CANCELED) || !$this->log(0, $message)) {
            $this->workflowModel->rollback();
            return false;
        }
        return true;
    }

    public function finish()
    {
        $model = $this->getModel();
        $newValue = is_array($model['new_value']) ? $model['new_value'] : json_decode($model['new_value'], true);
        $data = array();
        $attributes = $this->getAttributes();
        foreach ($attributes as $attribute) {
            if (isset($attribute['mod']) && $attribute['mod']=='control') {continue;}
            if (isset($newValue[$attribute['name']])) $data[$attribute['name']] = $newValue[$attribute['name']];
        }
        $config = $this->getConfig();
        $this->workflowModel->table($model['model'])
            ->where(array($config['primary_key'] => $model['model_id']))
            ->update($data);
        return true;
    }

    /**
     * @param $stage string 权限组
     * @param int $status
     * @return bool
     */
    public function updateWorkflow($stage = null, $status = 10)
    {
        try {
            $groupConfig = $this->getGroupConfig($stage);
            $timeout = isset($groupConfig['timeout']) ? $groupConfig['timeout'] : 0;
        } catch (Exception $e) {
            $timeout = 0;
        }
        $model = $this->getModel();
        $update = array('stage' => $stage, 'status' => $status,
            'timeout_at' => TIMESTAMP + $timeout,
            'updated_at' => TIMESTAMP);
        $this->workflowModel->table('workflow')->where(array('id' => $model['id']))->update($update);
        return true;
    }

    public function log($opinion = 1, $message = null, $attachment = null)
    {
        $model = $this->getModel();
        $data = array(
            'workflow_id' => $model['id'],
            'opinion' => $opinion,
            'stage' => $this->getGroup(),
            'message' => $message,
            'role' => $this->role,
            'user' => $this->user,
            'timeout_at' => $model['timeout_at'],
            'created_at' => TIMESTAMP,
        );
        if ($attachment !== null) {
            $data['attachment'] = is_array($attachment) ? json_encode($attachment) : $attachment;
        }
        //$this->debug($data);
        $res = $this->workflowModel->table('workflow_log')->insert($data);
        //$this->debug($this->workflowModel->getLastSql());
        return true;
    }

    /**
     * 设置工作流类型
     * @param $type integer|string
     * @return mixed
     * @throws string
     */
    public function setTpe($type)
    {
        if (isset($this->types[$type])) return $this->_type = $this->types[$type];
        if (in_array($type, $this->types)) return $this->_type = $this->types[$type];
        throw new Exception('错误的类型');
    }

    /**
     * 获取当前工作流类型
     * @return int|mixed
     * @throws Exception
     */
    public function getType()
    {
        if ($this->_type !== null) return $this->_type;
        $model = $this->getModel();
        if (isset($model['type']) && isset($this->types[$model['type']]))
            return $this->_type = $this->types[$model['type']];
        throw new Exception('操作数据必须 包含type字段');
    }

    /**
     * @param null $type
     * @return WorkflowHandler
     * @throws Exception
     */
    public function getHandler($type = null)
    {
        if ($this->_handler !== null) return $this->_handler;
        if ($type === null) $type = $this->getType();
        $className = ucfirst($type);
        $fileName = __DIR__ . '/workflow/' . $className . '.php';
        if (file_exists($fileName)) {
            require_once($fileName);
            if (class_exists($className)) return new $className();
        }
        throw new Exception('找不到对应工作流类型处理对象！类型：' . $type);

    }

    /**
     * 获取当前类型配置信息
     * @return array
     */
    public function getConfig()
    {
        if ($this->_config !== null) return $this->_config;
        $handler = $this->getHandler();
        return $this->_config = $handler->getConfig();
    }

    public function getAttributes()
    {
        if ($this->_attributes !== null) return $this->_attributes;
        return $this->getHandler()->getAttributes();
        /*if ($config === null) $config = $this->getConfig();
        if (!isset($config['attributes']) || empty($config['attributes'])) {
            throw new Exception('配置信息有误，请检查配置代码！');
        }
        $attributes = $config['attributes'];
        if (is_string($attributes)) {
            return $this->_attributes = array(array('label' => ucfirst($attributes), 'name' => $attributes, 'type' => 'text'));
        } else if (is_array($attributes)) {
            if (isset($attributes[0]) && is_array($attributes[0])) return $this->_attributes = $attributes;
            elseif (isset($attributes['name']) && is_string($attributes['name'])) return $this->_attributes = array($attributes);
        }
        return $this->_attributes = array();*/
    }

    /**
     * 获取当前表单
     * @param $isNew boolean
     * @return string
     * @throws Exception
     */
    public function getForm($isNew = false)
    {
        $attributes = $this->getAttributes();
        $group = $this->getGroup();
        $model = $this->getModel();
        $action = $this->getHandler()->getAction($this->getGroup());
        $res = '<form method="POST"><div class="title"><h3>审批处理</h3></div>';
        $hideControl = false;
        foreach ($attributes as $attribute) {
            if (!isset($attribute['on']) || in_array($group, (array)$attribute['on'])) {
                if(isset($attribute['mod'])&&$attribute['mod'] = 'control'&&isset($attribute['hide_control'])&&$attribute['hide_control'] == true ) $hideControl = true;
                $type = isset($attribute['type']) ? $attribute['type'] : 'text';
                $className = 'Renderer' . ucfirst($type);
                $fileName = __DIR__ . '/workflow/' . $className . '.php';
                if (!file_exists($fileName)) {
                    throw new Exception('找不到对应渲染器！类型：' . $type);
                }
                require_once($fileName);
                /** @var Renderer $render */
                $render = new  $className();
                $res .= $render->input($attribute, $model);
            }
        }
        if ($isNew) {
            $res .= <<<HTML
<div class="bot">
	<a href="JavaScript:;" nc_type="submit_btn" class="ncap-btn-big ncap-btn-green">提交申请</a>
</div>
HTML;
        } else if($hideControl){

            $res .= <<<HTML

    <dl class="row">
      <dt class="tit">审核意见</dt>
      <dd class="opt"><textarea id="message" name="message"></textarea></dd>
    </dl>
    <div class="bot">
	<a href="JavaScript:;" nc_type="submit_btn" class="ncap-btn-big ncap-btn-green">确认提交</a>
	</div>
<script >
var model_id = '{$model['model_id']}';
var handleAction = '{$action}';
</script>
HTML;
        }else {
            $res .= <<<HTML

    <dl class="row">
      <dt class="tit">是否同意</dt>
      <dd class="opt"><input type="radio" name="opinion" value="1" checked="checked"> 同意 &nbsp; <input type="radio" name="opinion" value="0"> 不同意</dd>
    </dl>
    <dl class="row">
      <dt class="tit">审核意见</dt>
      <dd class="opt"><textarea id="message" name="message"></textarea></dd>
    </dl>
    <div class="bot">
	<a href="JavaScript:;" nc_type="submit_btn" class="ncap-btn-big ncap-btn-green">确认提交</a>
	</div>
<script >
var model_id = '{$model['model_id']}';
var handleAction = '{$action}';
</script>
HTML;
        }
        return $res;
    }

    public function getView()
    {
        $attributes = $this->getAttributes();
        $model = $this->getModel();
        $res = '';
        foreach ($attributes as $attribute) {
            if ((isset($attribute['attachment']) && $attribute['attachment'])||(isset($attribute['mod']) && $attribute['mod']=='control')) {
                continue;
            }
            $type = isset($attribute['type']) ? $attribute['type'] : 'text';
            $className = 'Renderer' . ucfirst($type);
            $fileName = __DIR__ . '/workflow/' . $className . '.php';
            if (!file_exists($fileName)) {
                throw new Exception('找不到对应渲染器！类型：' . $type);
            }
            require_once($fileName);
            /** @var Renderer $render */
            $render = new  $className();
            $res .= $render->output($attribute, $model);
        }

        return $res;
    }

    public function getAttachment()
    {
        $attributes = $this->getAttributes();
        $model = $this->getModel();
        $res = '';
        foreach ($attributes as $attribute) {
            if (isset($attribute['attachment']) && $attribute['attachment']) {
                $type = isset($attribute['type']) ? $attribute['type'] : 'text';
                $className = 'Renderer' . ucfirst($type);
                $fileName = __DIR__ . '/workflow/' . $className . '.php';
                if (!file_exists($fileName)) {
                    throw new Exception('找不到对应渲染器！类型：' . $type);
                }
                require_once($fileName);
                /** @var Renderer $render */
                $render = new  $className();
                $res .= $render->output($model);
            }
        }

        return $res;
    }

    /**
     * 根据模型获取启动用户组
     * @param array $config
     * @return string
     * @throws Exception
     */
    public function getStartGroup($config = null)
    {
        $group = $this->getHandler()->getStartGroup();
        if(is_string($group)) return $group;
        $model = $this->getModel();
        if($model['role'] == self::ROLE_SELLER) return '商家';
        /** @var adminModel $adminModel */
        $adminModel = Model('admin');
        $admin = $adminModel->infoAdmin(array('admin_name'=>$model['user']));
        if($admin['admin_gid'] ==='0') return '超级管理员';
        $this->debug($admin);
        /** @var gadminModel $gadminModel */
        $gadminModel = Model('gadmin');
        $gadmin = $gadminModel->getGadminInfoById($admin['admin_gid']);
        return $gadmin['gname'];
    }

    /**
     * 获取指定/当前用户组工作流配置
     * @param array $config
     * @param string $group
     * @return mixed
     * @throws Exception
     */
    public function getGroupConfig($group = null)
    {
        return $this->getHandler()->getGroupConfig($group === null ? $this->getGroup() : $group);
    }

    /**
     * 获取批准配置
     * @param string $group
     * @return string
     */
    public function getApproveConfig($group = null)
    {
        return $this->getHandler()->getApproveConfig($group === null ? $this->getGroup() : $group);
    }

    /**
     * 获取拒绝配置
     * @param string $group
     * @return string
     */
    public function getRejectConfig($group = null)
    {
        return $this->getHandler()->getRejectConfig($group === null ? $this->getGroup() : $group);
    }

    /**
     * 设置工作流模型数据
     * @param array $model
     * @return mixed
     */
    public function setModel($model)
    {
        return $this->_model = $model;
    }

    /**
     * 获取工作流模型数据
     * 此数据需要预先调用setModel($model)设置
     * @return array
     * @throws Exception
     */
    public function getModel()
    {
        if ($this->_model === null) throw new Exception('没有设置操作数据');
        return $this->_model;
    }

    /**
     * 获取当前审批节点
     * @return mixed|string
     */
    public function getStage()
    {
        $model = $this->getModel();
        if (isset($model['stage'])) return $model['stage'];
        return '';
    }

    public function setGroup($group)
    {
        return $this->_group = $group;
    }

    public function getGroup()
    {
        if ($this->_group !== null) return $this->_group;
        throw new Exception('未设置用户组');
    }

    public function debug($info = null)
    {
        if (C('ON_DEV')) v($info, 0);
    }

}
