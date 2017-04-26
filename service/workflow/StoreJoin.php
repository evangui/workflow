<?php

require_once('WorkflowHandler.php');

class StoreJoin extends WorkflowHandler
{

    public function getId()
    {
        return 53;
    }
    public function getConfig()
    {
        return array(
            'name' => '商家入驻审批流程',
            'model' => 'store_joinin',
            'primary_key' => 'member_id',
			'action' => 'action',
            'attributes' => array(
				array('name' => 'error_type', 'type' => 'select', 'items'=>array('no_error'=>'没问题', 'error_company'=>'商家资质有问题','error_commis'=>'商家类型、保证金或佣金比例有问题',), 'label' => '错误类型', 'on' => array('公司商务'), 'notice' => '审批不通过时，请选择错误类型'),
				array('name' => 'error_type', 'type' => 'select', 'items'=>array('no_error'=>'没问题', 'error_company'=>'商家资质有问题','error_commis'=>'商家类型、保证金或佣金比例有问题',), 'label' => '错误类型', 'on' => array('财务部'), 'notice' => '审批不通过时，请选择错误类型'),
            ),
            /**
             * attributes 参数说明
             * value ：input名称/数据表字段名
             * type：input类型
             * label：input显示名称
             * on：显示条件
             * attachment：是否附件
             * when：附件显示条件，一般为字符串，在非附件项目变动时触发方法中予以处理
             */
            'reference' => '/admin/modules/shop/index.php?act=store&op=store_joinin_detail&member_id={id}',
            'start' => '商家',// 启动用户组
            'flow' => array(
				'商家' => array(
                    'approve' => function ($model) {
                        return '运营部';
                    },
                    'reject' => '',
                ),
                '运营部' => array(
					'timeout'=>3600,
                    'approve' => function ($model) {
                    	$model_store_joinin = Model('store_joinin');
        				$joinin_detail = $model_store_joinin->getOne(array('member_id' => $model['model_id']));
        				if($joinin_detail['manage_type']=='unselect'){
        					throw new Exception( "商家类型未设置" );
        				}
                        return '总经理';
                    },
                    'reject' => function ($model) {
						$model_store_joinin = Model('store_joinin');
						$param['joinin_state'] = STORE_JOIN_STATE_VERIFY_FAIL;
						$param['joinin_message'] = $model['message'];
						$model_store_joinin->modify($param, array('member_id'=>$model['model_id']));
						return '商家';
					},
                    'attachment' => array(
                        ''
                    )
                ),
				'总经理' => array(
					'timeout'=>3600,
                    'approve' => function ($model) {
                        return '公司商务';
                    },
                    'reject' => '运营部',
                    'attachment' => array(
                        ''
                    )
                ),
                '公司商务' => array(
                    'timeout'=>3600,
                    'approve' => function($model){
                        return '财务部';
                    },
                    'reject' => function($model){
						$error_type = $_POST['error_type'];
						if($error_type=='no_error' || is_null($error_type)) throw new Exception( '未选择错误类型' );
						
						if( $error_type == 'error_commis' ) {
							return '运营部';
						} else {
							$model_store_joinin = Model('store_joinin');
							$param['joinin_state'] = STORE_JOIN_STATE_VERIFY_FAIL;
							$param['joinin_message'] = $model['message'];
							$model_store_joinin->modify($param, array('member_id'=>$model['model_id']));
							return '商家';
						}
                        
                    },
                ),
				'财务部' => array(
                    'timeout'=>3600,
                    'approve' => function($model){
						$model_store_joinin = Model('store_joinin');
						$joinin_detail = $model_store_joinin->getOne(array('member_id' => $model['model_id']));
						$joinin_detail['joinin_message'] = $model['message'] ;
						
						$param = array();
						$param['joinin_state'] = STORE_JOIN_STATE_VERIFY_SUCCESS;
						$param['joinin_message'] = $_POST['joinin_message'];
						$model_store_joinin->modify($param, array('member_id' => $model['model_id']));
						
						if( $joinin_detail['sc_bail'] == 0 ) {
							$storeJoin = new StoreJoin;
							$res = $storeJoin->store_joinin_verify_open($joinin_detail);
							if( !$res['state'] ) {
								throw new Exception( $res['msg'] );
							}
							return 'closed';
						}
                        return '商家';
                    },
                    'reject' => function($model){
						$error_type = $_POST['error_type'];
						if($error_type=='no_error' || is_null($error_type)) die;
						
						if( $error_type == 'error_commis' ) {
							return '运营部';
						} else {
							$model_store_joinin = Model('store_joinin');
							$param['joinin_state'] = STORE_JOIN_STATE_VERIFY_FAIL;
							$param['joinin_message'] = $model['message'];
							$model_store_joinin->modify($param, array('member_id'=>$model['model_id']));
							return '商家';
						}
                        
                    },
                ),
				'出纳' => array(
					'timeout'=>3600,
                    'approve' => function ($model) {
						$model_store_joinin = Model('store_joinin');
						$joinin_detail = $model_store_joinin->getOne(array('member_id' => $model['model_id']));
						$joinin_detail['joinin_message'] = $model['message'] ;
						
						$storeJoin = new StoreJoin;
						$res = $storeJoin->store_joinin_verify_open($joinin_detail);
						if( !$res['state'] ) {
							throw new Exception( $res['msg'] );
						}
                        return 'closed';
                    },
                    'reject' => function ($model){
						$model_store_joinin = Model('store_joinin');
						$param['joinin_state'] = STORE_JOIN_STATE_PAY_FAIL;
						$param['joinin_message'] = $model['message'];
						$model_store_joinin->modify($param, array('member_id'=>$model['model_id']));
						return '商家';
					},
                    'attachment' => array(
                        ''
                    )
                ),
            ),
        );
    }

    function store_joinin_verify_open($joinin_detail)
    {
        $model_store_joinin = Model('store_joinin');
        $model_store = Model('store');
        $model_seller = Model('seller');
        
        if( $joinin_detail['manage_type'] == 'unselect' ) {
        	return array('state'=>false, 'msg' => '未设置商家类型');
        }

        //验证商家用户名是否已经存在
        if ($model_seller->isSellerExist(array('seller_name' => $joinin_detail['seller_name']))) {
			return array('state'=>false, 'msg' => '商家用户名已存在');
        }

        $param = array();
        $param['joinin_state'] = STORE_JOIN_STATE_FINAL;
        $param['joinin_message'] = $joinin_detail['joinin_message'];
        $model_store_joinin->modify($param, array('member_id' => $joinin_detail['member_id']));

		//开店
		$shop_array = array();
		$shop_array['member_id'] = $joinin_detail['member_id'];
		$shop_array['member_name'] = $joinin_detail['member_name'];
		$shop_array['seller_name'] = $joinin_detail['seller_name'];
		$shop_array['manage_type'] = $joinin_detail['manage_type'];
		$shop_array['grade_id'] = $joinin_detail['sg_id'];
		$shop_array['store_name'] = $joinin_detail['store_name'];
		$shop_array['sc_id'] = $joinin_detail['sc_id'];
		$shop_array['store_company_name'] = $joinin_detail['company_name'];
		$shop_array['province_id'] = $joinin_detail['company_province_id'];
		$shop_array['area_info'] = $joinin_detail['company_address'];
		$shop_array['store_address'] = $joinin_detail['company_address_detail'];
		$shop_array['store_zip'] = '';
		$shop_array['store_zy'] = '';
		$shop_array['store_state'] = 1;
		$shop_array['store_time'] = time();
		$shop_array['store_end_time'] = strtotime(date('Y-m-d 23:59:59', strtotime('+1 day')) . " +" . intval($joinin_detail['joinin_year']) . " year");
		$store_id = $model_store->addStore($shop_array);

		if ($store_id) {
			//写入商家账号
			$seller_array = array();
			$seller_array['seller_name'] = $joinin_detail['seller_name'];
			$seller_array['member_id'] = $joinin_detail['member_id'];
			$seller_array['seller_group_id'] = 0;
			$seller_array['store_id'] = $store_id;
			$seller_array['is_admin'] = 1;
			$state = $model_seller->addSeller($seller_array);
		}

		if ($state) {
			Language::read('store,store_grade');
			// 添加相册默认
			$album_model = Model('album');
			$album_arr = array();
			$album_arr['aclass_name'] = Language::get('store_save_defaultalbumclass_name');
			$album_arr['store_id'] = $store_id;
			$album_arr['aclass_des'] = '';
			$album_arr['aclass_sort'] = '255';
			$album_arr['aclass_cover'] = '';
			$album_arr['upload_time'] = time();
			$album_arr['is_default'] = '1';
			$album_model->addClass($album_arr);

			$model = Model();
			//插入店铺扩展表
			$model->table('store_extend')->insert(array('store_id' => $store_id));
			$msg = Language::get('store_save_create_success');

			//插入店铺绑定分类表
			$store_bind_class_array = array();
			$store_bind_class = unserialize($joinin_detail['store_class_ids']);
			$store_bind_commis_rates = explode(',', $joinin_detail['store_class_commis_rates']);
			for ($i = 0, $length = count($store_bind_class); $i < $length; $i++) {
				list($class1, $class2, $class3) = explode(',', $store_bind_class[$i]);
				$store_bind_class_array[] = array(
					'store_id' => $store_id,
					'commis_rate' => $store_bind_commis_rates[$i],
					'class_1' => $class1,
					'class_2' => $class2,
					'class_3' => $class3,
					'state' => 1
				);
			}
			$model_store_bind_class = Model('store_bind_class');
			$model_store_bind_class->addStoreBindClassAll($store_bind_class_array);
			$msg = "开店成功";
		} else {
			$msg = "开店失败";
		}
		return array('state'=>$state, 'msg' => $msg);
    }
}