<?php

namespace common\controllers\v1;

use common\models\Commodity;
use common\models\CompanyPolicyNo;
use common\models\Country;
use common\models\DgnoteTrailerState;
use common\models\Payment;
use common\models\SettlingAgent;
use common\models\User;
use Yii;
use yii\web\Controller;
use yii\filters\AccessControl;
use yii\filters\auth\HttpBearerAuth;

/**
 * Site controller
 */
class CommonController extends Controller
{

    /**
     * @inheritdoc
     */

    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::className(),
                'only' => ['getcompany', 'postcompany'],
                'rules' => [
                    [
                        'actions' => ['getcompany', 'postcompany'],
                        'allow' => true,
                        'roles' => ['?'],
                    ],
                    [
                        'actions' => ['getcompany', 'postcompany'],
                        'allow' => true,
                        'roles' => ['@'],
                        // 'authenticator' => ['authMethods' => [HttpBearerAuth::className()]]
                    ]
                ]
            ],
        ];
    }

    public function beforeAction($action)
    {
        $this->enableCsrfValidation = false;
        if (parent::beforeAction($action)) {
            return true;
        }
        return false;
    }

    /**
     * @inheritdoc
     */
    public function actions()
    {
        return [
            'error' => [
                'class' => 'yii\web\ErrorAction',
            ],
            'captcha' => [
                'class' => 'yii\captcha\CaptchaAction',
                'fixedVerifyCode' => YII_ENV_TEST ? 'testme' : null,
            ],
        ];
    }

    /**
     * Displays homepage.
     *
     * @return mixed
     */
    public function actionIndex($id)
    {
        $user = \common\models\Company::findOne(['id' => $id]);
        return json_encode($user->attributes);
    }   

    public function actionCreate()
    {
        if (Yii::$app->getRequest()->getRawBody()) {

            $data = json_decode(Yii::$app->getRequest()->getRawBody(), true);

            // initialize variables
            $sgst_amount = 0;
            $cgst_amount = 0;
            $igst_amount = 0;
            $bajaj_sgst_amount = 0;
            $bajaj_cgst_amount = 0;
            $bajaj_igst_amount = 0;
            $dgnote_sgst_amount = 0;
            $dgnote_cgst_amount = 0;
            $dgnote_igst_amount = 0;
            $aSerialize = [];
            $userDetails = null;

            // retrieve user details
            $getuserDetails = $this->getUserDetails($data);
            if(isset($getuserDetails['error']) && !empty($getuserDetails['error'])) {
                return $getuserDetails['error'];
            } else {
                $userDetails = $getuserDetails['user'];
            }

            // get country based on transit type
            $getcountry = $this->getCountry($data);

            if(isset($getcountry['error']) && !empty($getcountry['error'])) {
                return $getcountry['error'];
            } else {
                $country = Country::find()->where(['name' => $getcountry['country']])->andWhere(['status' => 1])->one();
            }

            $data['gstin_sez'] = 0;

            $validateData = $this->validateRequestData($data);

            $data['w2wcheck'] = isset($data['w2wcheck']) ? $data['w2wcheck'] : 0;

            if(isset($data['is_odc']) && $data['is_odc'] == 1 && isset($data['commodity']) && !empty($data['commodity'])) {
                $getCommodity = Commodity::find()->where(['id' => $data['commodity']])->andWhere(['is_odc' => 1])->one();
                if(isset($getCommodity) && !empty($getCommodity) ) {
                    $data['is_odc'] = 1;
                } else {
                    return 'This commodity is not allowed for ODC please contact DgNote Administartor!';
                }
            } else {
                $data['is_odc'] = 0;
            }
            $data['additional_duty'] = isset($data['additional_duty']) ? $data['additional_duty'] : 0;
            $data['additional_freight'] = isset($data['additional_freight']) ? $data['additional_freight'] : 0;
            $data['is_sez'] = isset($data['is_sez']) ? $data['is_sez'] : 0;
            $data['mark_no'] = isset($data['mark_no']) ? $data['mark_no'] : '';

            if(isset($validateData['error']) && !empty($validateData['error'])) {
                return json_encode($validateData['error']);
            } else {
                // validate gstin based parameters
                if(isset($data['gstin']) && !empty($data['gstin'])){
                    $validate_gstin = $this->validateGstin($data);

                    if(isset($validate_gstin['error']) && !empty($validate_gstin['error'])) {
                        return $validate_gstin['error'];
                    }else {
                        $data['gstin_sez'] = $validate_gstin['gstin_sez'];
                    }
                }

                //validate pin code
                $validatePin = $this->validatePincode($data['billing_state'], $data['billing_pincode']);

                if(isset($validatePin['error']) && !empty($validatePin['error'])){
                    return $validatePin['error'];
                }

                $user_id = $userDetails->id;
                $company_id = $userDetails->company_id;
                $input_coverage = isset($data['coverage'])? $data['coverage']: '';

                $getcoverage = $this->getCoverage($data['commodity'], $data['transit_mode'], $data['transit_type'], $input_coverage);
                if(isset($getcoverage)) {
                    $splitcoverage = explode("+", $getcoverage['coverage']);
                    $coverage = $splitcoverage[0];
                    $coverage_war = $splitcoverage[1];
                } else {
                    return 'Coverage Not Map for Select Incoterm commodity in transit type please contact admintrator!';
                } 

                $data['coverage_war'] = $coverage_war;
                $data['coverage'] = $coverage;
                $objBajajGroup = \common\models\CompanyPolicyCargoCertificate::checkBajajGroupExitOrNotWithInfo($company_id);
                //Add company id with token after completion 
                $bajajGroup = isset($objBajajGroup->masterPolicy->bajaj_group) ? $objBajajGroup->masterPolicy->bajaj_group : '';
                $data['country_type'] = isset($country->country_category) ? $country->country_category : '';

                $dgnoteRate = Yii::$app->commonutils
                    ->getAllRatesForApi(
                        2,
                        $data['commodity'],
                        $coverage,
                        $data['transit_type'],
                        $data['transit_mode'],
                        $data['w2wcheck'],
                        $data['is_odc'],
                        false,
                        0,
                        445,
                        $bajajGroup,
                        $data['country_type'],
                        0,
                        0
                    );
                $invoice_amount_inr = $data['invoice_amount'] * $data['exchange_rate'];                
                $excess_amount = ($invoice_amount_inr * $data['extra_percentage_amount']) / 100;
                $sum_insured = $invoice_amount_inr +  $excess_amount + $data['additional_duty'] + $data['additional_freight'];

                $data['invoice_amount_inr'] = isset($data['invoice_amount_inr'])?$data['invoice_amount_inr'] : $invoice_amount_inr;


                $net_premium = ($dgnoteRate['user_rate'] * $sum_insured) / 100;
                $bajaj_premium = ($dgnoteRate['bajaj_rate'] * $sum_insured) / 100;
                $dgnote_premium = ($dgnoteRate['dgnote_rate'] * $sum_insured) / 100;

                $user_premium = isset($dgnoteRate['user_premium']) ? $dgnoteRate['user_premium'] : 0;
                if ($net_premium < $user_premium) {
                    $net_premium = $user_premium;
                    $dgnote_premium = $user_premium;
                }


                $data['user_rate'] = $dgnoteRate['user_rate'];
                $data['bajaj_rate'] = $dgnoteRate['bajaj_rate'];
                $data['dgnote_rate'] = $dgnoteRate['dgnote_rate'];
                $data['user_premium'] = $dgnoteRate['user_premium'];
                $data['dgnote_premium'] = $dgnoteRate['dgnote_premium'];

                $gstObj = \yii::$app->gst->getGstProductWise(\yii::$app->params['insuranceproductName']);

                $igst_rate = $gstObj->igst_rate;
                $sgst_rate = $gstObj->sgst_rate;
                $cgst_rate = $gstObj->cgst_rate;

                $invoice_comp_gst = 27;

                if ($data['is_sez'] == 0) {
                    if (substr($data['gstin'], 0, 2) == $invoice_comp_gst && $data['gstin_sez'] == 0) {
                        $sgst_amount = ($net_premium * $sgst_rate) / 100;
                        $cgst_amount = ($net_premium * $cgst_rate) / 100;

                        $bajaj_sgst_amount = ($bajaj_premium * $sgst_rate) / 100;
                        $bajaj_cgst_amount = ($bajaj_premium * $cgst_rate) / 100;

                        $dgnote_sgst_amount = ($dgnote_premium * $sgst_rate) / 100;
                        $dgnote_cgst_amount = ($dgnote_premium * $cgst_rate) / 100;
                    } else {
                        $igst_amount = ($net_premium * $igst_rate) / 100;

                        $bajaj_igst_amount = ($bajaj_premium * $igst_rate) / 100;
                        $dgnote_igst_amount = ($dgnote_premium * $igst_rate) / 100;
                    }
                }

                $aSerialize['igst_rate'] = $igst_rate;
                $aSerialize['sgst_rate'] = $sgst_rate;
                $aSerialize['cgst_rate'] = $cgst_rate;
                $aSerialize['ncc_cess_rate'] = 0;

                $aSerialize['igst'] = $igst_amount;
                $aSerialize['sgst'] = $sgst_amount;
                $aSerialize['cgst'] = $cgst_amount;
                $aSerialize['ncc_cess'] = 0;

                $get_gst_serialize = serialize($aSerialize);
                $totalgst = $igst_amount + $sgst_amount + $cgst_amount;
                $bajaj_totalgst = $bajaj_igst_amount + $bajaj_sgst_amount + $bajaj_cgst_amount;
                $dgnote_totalgst = $dgnote_igst_amount + $dgnote_sgst_amount + $dgnote_cgst_amount;
                $stamp_duty_amount = 0;
                $total_premium = number_format(round($net_premium + $totalgst + $stamp_duty_amount), 2, '.', '');
                $bajaj_total_premium = number_format(round($bajaj_premium + $bajaj_totalgst + $stamp_duty_amount), 2, '.', '');
                $dgnote_total_premium = number_format(round($dgnote_premium + $dgnote_totalgst + $stamp_duty_amount), 2, '.', '');


                $productModel = new \common\models\InsuranceProduct();
                $aInsuranceProduct = $productModel->getProductCodeByMatrix(
                    2,
                    $data['transit_type'],
                    $data['transit_mode']
                );

                $data['sum_insured'] = $sum_insured;
                $data['stamp_duty_amount'] = $stamp_duty_amount;
                $data['branch'] = 'branch';
                $data['company_id'] = $company_id;
                $data['product_code'] = $aInsuranceProduct['code'];
                    
                $transitTypeModel = new \common\models\TransitType();
                $transitTypeId = $transitTypeModel->getIdByTransitType($data['transit_type']);

                $certificateAgent = \common\models\CertificateAgent::find()->where([
                    'transit_type_id' => $transitTypeId, 'term_sale_id' => $data['terms_of_sale']
                ])->one();
                if ($certificateAgent->display_value == 2) {
                    $country_id = isset($country->id)? $country->id : '';
                } else {
                    $country_id = 174; 
                } 

                $getsurveyor = SettlingAgent::find()->where(['country_id' => $country_id])->one();
                $data['surveyor_id'] = $getsurveyor->id;
                $data['surveyor_address'] = $getsurveyor->address;
                $data['surveyor_agent'] = $getsurveyor->name;
                $data['surveyor_city'] = $getsurveyor->city;
                
                $data['tnc'] = isset($data['tnc'])? $data['tnc'] : 1;//Need to check
                $data['user_detail'] = isset($data['user_detail'])? $data['user_detail'] : 1;//Need to check
                $data['billing_detail'] = isset($data['billing_detail'])? $data['billing_detail'] : 2;//Need to check
                $data['contact_name'] = 'First Name' . " " . 'Last Name';
                $data['mobile'] = '9043233423';
                $data['country'] = 'India';
                $data['total_premium'] = $total_premium;

                $data['premium'] = $this->removeCommaFromAmount($net_premium);
                $data['gstin'] = $data['gstin'];
                $data['pan'] = \yii::$app->gst->getPanFromGSTNo($data['gstin']);
                $data['pincode'] = $data['pincode'];
                $data['w2w'] = isset($data['w2wcheck']) ? $data['w2wcheck'] : 0;
                $data['user_detail'] = 0;
                $data['billing_detail'] = 2;
                $data['transit_commenced'] = isset($data['transit_commenced'])? $data['transit_commenced'] :'No';
                $data['is_sez'] = isset($data['is_sez']) ? $data['is_sez'] : 0;
                if ($data['country_type'] == 'S') {
                    $data['is_offline'] = 1;
                    $data['country_offline'] = 1;
                } elseif ($data['is_odc'] == 1) {
                    $data['is_offline'] = 1;
                } elseif ($data['sum_insured'] >= \Yii::$app->params['maxInsurancePremium']) {
                    $data['is_offline'] = 1;
                    $data['cr_2_offline'] = 1;
                } elseif ($data['transit_commenced'] == 'Yes') {
                    $data['is_offline'] = 1;
                } else {
                    $data['is_offline'] = 0;
                    $data['cr_2_offline'] = 0;
                    $data['country_offline'] = 0; 
                } 

                $data['service_tax_amount'] = $totalgst;
                $data['bajaj_group'] = $bajajGroup;
                $data['dgnote_commission'] = $this->getDgnoteRate(
                    $data['commodity'],
                    $data['w2wcheck'],
                    $data['is_odc'],
                    $coverage
                );
                $data['destination_country'] = $data['destination_country'];

                if ($transitTypeId && $data['commodity']) {
                    $data['valuation_basis'] = $this->getBov($transitTypeId, $data['commodity']);
                }

                $objCargo = new \frontend\insurance\models\CargoFormApi();
                $objCargo->assignCargoForOffline($data);
                $objCargo->bajaj_group = '';
                $objCargo->is_uploaded = 1;
                $objCargo->upload_file_status = 'success';
                $objCargo->tnc = isset($data['tnc'])?$data['tnc']: 1;
                $objCargo->is_commenced = 0; 
                $transitTypeModel = new \common\models\TransitType();
                
                $upload_invoice = isset($data['upload_invoice'])? $data['upload_invoice'] : '';
                $upload_packing_list = isset($data['upload_packing_list'])? $data['upload_packing_list'] : '';
                $upload_offline_format = isset($data['upload_offline_format'])? $data['upload_offline_format'] : '';
                
                $upload_invoice_type = isset($data['upload_invoice_type'])? $data['upload_invoice_type'] : '';
                $upload_packing_list_type = isset($data['upload_packing_list_type'])? $data['upload_packing_list_type'] : '';
                $upload_offline_format_type = isset($data['upload_offline_format_type'])? $data['upload_offline_format_type'] : '';

                if($data['is_offline'] == 1 && $data['is_odc'] == 1) {
                    $quoteObj = $objCargo->save($user_id, $upload_invoice, $upload_packing_list, $upload_offline_format, $upload_invoice_type, $upload_packing_list_type, $upload_offline_format_type);
                    $mailQueueObj = new \common\models\EmailQueue();
                    $mailQueueObj->insertQueueForMail(
                        $quoteObj->id,
                        'insurance_is_odc_for_user', 
                        1
                    );
                } elseif($data['is_offline'] == 1 && $data['is_odc'] == 0) {
                    $quoteObj = $objCargo->save($user_id, $upload_invoice, $upload_packing_list, $upload_offline_format, $upload_invoice_type, $upload_packing_list_type, $upload_offline_format_type);
                    $mailQueueObj = new \common\models\EmailQueue();
                    $mailQueueObj->insertQueueForMail(
                        $quoteObj->id,
                        'insurance_offline_format_for_user',
                        1
                    ); 
                } else {
                    if ($quoteObj = $objCargo->save($user_id, $upload_invoice, $upload_packing_list, $upload_offline_format, $upload_invoice_type, $upload_packing_list_type, $upload_offline_format_type)) { 
                        $policyDetail = $this->getPolicyDetail("", true, 'All');
    
                        // Generate Policy  insert records to mi_policy and DgNote Transaction\common\models\Policy
                        //entry in mi_policy table

                        $setPolicyDetail = $this->setPolicyDetail($policyDetail, $quoteObj, $user_id); 
    
                        if($setPolicyDetail) {
    
                            //save in DgnoteTransaction table  
                            $setTransaction = $this->setTransaction($user_id, $quoteObj, $setPolicyDetail);  
                            
                            // Generate Invoice 
                            $generateInvoice = $this->generateInvoice($quoteObj, $user_id);

                            //inserting invoice details if invoice created
                            if ($generateInvoice) {
                                
                                $setInvoiceDetail = $this->setInvoiceDetail($quoteObj, $generateInvoice, $get_gst_serialize, $data);
    
                                //purchase entry
    
                                //save in dgnote_payment table payment type id 1 for purchase and payment mode is offline

                                $insertPurchaseData = $this->insertPurchaseData($user_id, $quoteObj, $generateInvoice); 
    
                                if($insertPurchaseData) { 
                                    
                                    $insertPaymentData = $this->insertPaymentData($company_id, $quoteObj, $setPolicyDetail, $generateInvoice, $user_id);
                                    
                                    //inserting in dgnote_bajaj_premium table
                                    $insertBajajLedger = $this->insertBajajLedger($quoteObj, $bajaj_total_premium, $policyDetail);
    
                                    // Updating user account balance  
                                    $updateUserAccountBalance = $this->updateUserAccountBalance($data, $user_id, $policyDetail, $bajaj_total_premium);                                     
                                }
                            }
                        }
                        
                        $insertDataBajajPremium = $this->insertDataBajajPremium($quoteObj, $bajaj_premium, $bajaj_sgst_amount, $bajaj_cgst_amount, $bajaj_igst_amount, $sgst_rate, $cgst_rate, $igst_rate, $data, $bajaj_total_premium, $dgnote_premium, $dgnote_sgst_amount, $dgnote_cgst_amount, $dgnote_igst_amount, $dgnote_total_premium, $net_premium, $sgst_amount, $cgst_amount, $igst_amount, $total_premium);
    
                        return 'Data Update successfully';
                    } else {
                        json_encode($quoteObj->save());
                    }
                } 
                return 'Data Update successfully';
            } 
        } else {
            return json_encode(['error' => 'No data']);
        }
    } 

    // retrieve user details from token
    private function getUserDetails($data)
    {   
        $userData = [];
        if (isset($data['token']) && !empty($data['token'])) {
            $userData['user'] =  User::find()->where(['email' => $data['token']])->one();
            if(isset($userData['user']) && !empty($userData['user'])) {
                return $userData;
            } else {
                $userData['error'] = 'User Not Found';
                return $userData;
            }
        } else {
            $userData['error'] = 'User Not Found';
            return $userData;
        }
    }

    // get country based on transit type
    private function getCountry($data)
    {
        $country = '';
        $getValu = [];
        if (isset($data['transit_type']) && !empty($data['transit_type'])) {
            switch ($data['transit_type']) {
                case 'Import':
                    if (isset($data['origin_country']) && !empty($data['origin_country'])) {
                        $getValu['country'] = $data['origin_country'];
                        return $getValu;
                        break;
                    } else {
                        $getValu['error'] = 'Origin Country Not Found';
                        return $getValu;
                    }

                case 'Export':
                    if (isset($data['destination_country']) && !empty($data['destination_country'])) {
                        $getValu['country'] = $data['destination_country'];
                        return $getValu;
                        break;
                    } else {
                        $getValu['error'] = 'Destination Country Not Found';
                        return $getValu;
                    }

                default:
                    $getValu['country'] = 'India';
                    return $getValu;
                    break;
            }
        } else {
            $getValu['error'] = 'Transit Type Not Found';
            return $getValu;
        }

        return $country;
    } 

    public function validateRequestData($data)
    {   
        $valData = [];   
        if ($data) { 
            // return date('Y-m-d', strtotime($data['coverage_start_date']));
            $objCargo = new \frontend\insurance\models\CargoFormApi();
            $data["valuation_basis"] = (isset($data["valuation_basis"])) ? $data["valuation_basis"] : 'Invoice';
            $objCargo->scenario = \frontend\insurance\models\CargoFormApi::SCENARIO_COLUMNS;
            $objCargo->attributes = $data;
            if ($objCargo->validate()) {
                $valData['validate'] = 'success';
                return $valData;
            } else {
                $valData['error'] = $objCargo->errors;
                return $valData;
            }
        } else {
            $valData['error'] = 'error';
            return $valData;
        }
    } 

    public function validateGstin($data) {
        $gst_result = $this->Checkgstin($data['gstin']);
        $gst_data = [];
        if(isset($gst_result) && !empty($gst_result)){
            if($gst_result['gstin'] == "Mention GSTIN is not allowed for Sez."){
                $gst_data['error'] = $gst_result['gstin'];
                return $gst_data;
            } else if($gst_result['gstin'] == "API not Response") {
                $gst_data['error'] = $gst_result['gstin'];
                return $gst_data;
            } else if($gst_result['gstin'] == "GSTIN not verified, please ensure correct GSTIN entered.")
            {
                $gst_data['error'] = $gst_result['gstin'];
                return $gst_data;
            } else { 

                if($data['party_name'] != $gst_result['gstin']['search']['name']) {
                    $gst_data['error'] = 'GSTIN Party Name Not Match with Party Name';
                    return $gst_data;
                } elseif($data['billing_address'] != $gst_result['gstin']['search']['address']) {
                    $gst_data['error'] = 'GSTIN Address Not Match with Biilling Address';
                    return $gst_data;
                } elseif($data['billing_state'] != $gst_result['gstin']['search']['state']) {
                    $gst_data['error'] = 'GSTIN State Not Match with Biilling State';
                    return $gst_data;
                } elseif($data['billing_city'] != $gst_result['gstin']['search']['city']) {
                    $gst_data['error'] = 'GSTIN City Not Match with Biilling City';
                    return $gst_data;
                } elseif($data['billing_pincode'] != $gst_result['gstin']['search']['pincode']) {
                    $gst_data['error'] = 'GSTIN Pincode Not Match with Biilling Pincode';
                    return $gst_data;
                } else {
                    $gst_data['gstin_sez'] = ($gst_result['gstin']['search']['gstin_sez'] == "SEZ Unit")?1:0;
                    return $gst_data;
                } 
            }
        }
    }

    public function validatePincode($state, $pincode)
    {
        $dgnoteTrailerState = DgnoteTrailerState::find()->where(['name' => $state])->one();
        $pincode_data = [];
        if ($dgnoteTrailerState === null) {
            $pincode_data['error'] = 'Pincode does not belong to ' . $state . ' state';
            return $pincode_data;
        }
        if ($pincode < $dgnoteTrailerState->min_pin_code || $pincode > $dgnoteTrailerState->max_pin_code) {
            $pincode_data['error'] = 'Pincode must be greater than or equal to ' . $dgnoteTrailerState->min_pin_code . ' and less than or equal to ' . $dgnoteTrailerState->max_pin_code;
            return $pincode_data;
        }
    }

    public function Checkgstin($gstin, $sez = 0, $checksez = 0)
    {
            if ($sez == 2 && $checksez == 1) {
                $companyId = Yii::$app->user->identity->company_id;
                $objSez = \common\models\AllowedSezGstin::find();
                $objSezResult = $objSez->where([
                    'company_id' => $companyId,
                    'gstin' => $gstin
                ])->one();
                if (!$objSezResult) {
                    \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
                    return [
                        'gstin' => "Mention GSTIN is not allowed for Sez.",
                    ];
                }
            }
            $gstin = \Yii::$app->gst->getGstinDetails($gstin);
            \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
            return [
                'gstin' => $gstin,
            ]; 
    }

    public function getPolicyDetail($companyId = "", $iscargoPolicy = true, $policy_category)
    {
        if (!$iscargoPolicy == true) {
            $query = CompanyPolicyNo::find();
            $query->join('Inner JOIN', 'dgnote_masterpolicy_separation', 'dgnote_master_policy_detail.policy_separation_id = dgnote_masterpolicy_separation.id');
            $query->where(['container' => 1, 'dgnote_master_policy_detail.status' => 1, 'dgnote_master_policy_detail.policy_type' => 'dgnote']);
            $query->andwhere(['dgnote_masterpolicy_separation.type =dgnote']);
            return $query->one();
        } else {
            if ($companyId == "") {

                $query = CompanyPolicyNo::find();
                $query->join('Inner JOIN', 'dgnote_masterpolicy_separation', 'dgnote_master_policy_detail.policy_separation_id = dgnote_masterpolicy_separation.id');
                $query->where(['cargo' => 1, 'dgnote_master_policy_detail.status' => 1, 'dgnote_master_policy_detail.policy_type' => 'dgnote']);
                $query->andwhere(['dgnote_masterpolicy_separation.type' => $policy_category]);
                return $query->one();
            } else {
                $query = CompanyPolicyNo::find();
                $query->join('Inner JOIN', 'dgnote_company_policy_cargo_certificate', 'dgnote_company_policy_cargo_certificate.policy_detail_id = dgnote_master_policy_detail.id');
                $query->join('Inner JOIN', 'dgnote_masterpolicy_separation', 'dgnote_master_policy_detail.policy_separation_id = dgnote_masterpolicy_separation.id');
                $query->where(['cargo' => 1, 'dgnote_master_policy_detail.status' => 1, 'dgnote_master_policy_detail.policy_type' => 'direct']);
                $query->andwhere(['dgnote_company_policy_cargo_certificate.company_id' => $companyId]);
                $query->andwhere(['dgnote_masterpolicy_separation.type' => $policy_category]);
                return $query->one();
            }
        }
    } 

    public function getCoverage($commodity, $transitMode, $transitType, $inputCoverage)
    {
        $coverageModel = new \common\models\CoverageType();
        $aCoverageType = $coverageModel->getCoverageTypeByCoverageTypeMatrixForAPI($commodity, $transitType, $transitMode, $inputCoverage);

        if(count($aCoverageType) > 0) {
            $coverageModel = new \common\models\CoverageWar();
            $coverageWar = $coverageModel->getCoverageWarForApi($transitType, $transitMode);
    
            return [
                'coverage' => $aCoverageType['type'] . " " . $coverageWar,
            ];
        } else {
            return null;
        }


    }

    public function getAllRates(
        $insuranceProductType = 2,
        $commodity,
        $coverage,
        $transitType,
        $transitMode,
        $w2wCheck = 0,
        $isOdc = 0,
        $isThirdCountry = false,
        $noOfReturn = 3,
        $companyId = '',
        $bajajGroup = '',
        $countryType = '',
        $sumInsured = 0,
        $is_war_exclusion = 0
    ) {
        $w2wCheck = ($w2wCheck === 'Y' || $w2wCheck === 'YES' || $w2wCheck == 1) ? 1 : 0;
        $company = (!empty($companyId)) ? $companyId
            : 445;
        $commodityModel = new \common\models\Commodity();
        $commodityId = $commodityModel->getIdByCommodity($commodity);

        $isCheck = Yii::$app->issueCertificate->isCompanyRateExist(
            $company,
            $insuranceProductType,
            $commodityId,
            $isThirdCountry
        );

        $stndRateObj =  \common\models\StanderedCompanyRate::find()->where(['product_id' => $insuranceProductType, 'commodity_id' => $commodityId])->one();

        // $aRates = [];
        $userRate = $dgnoteRate = $bajajRate = '';

        if ($insuranceProductType == 1) {
            $query = \common\models\RateMatrix::find();
            $objBajaj = $query->select([
                'odc_basic_rate', 'odc_rate', 'dgnote_rate', 'basic_rate', 'w2w_rate', 'w2w_basic_rate'
            ])
                ->where(['commodity_id' => $commodityId])->one();
            $userRate = (!empty($isCheck->user_rate) && $isCheck->user_rate > 0) ? $isCheck->user_rate :
                $stndRateObj->user_rate;
            $dgnoteRate = (!empty($isCheck->dgnote_rate) && $isCheck->dgnote_rate > 0) ? $isCheck->dgnote_rate :
                $stndRateObj->dgnote_rate;
            $bajajRate = $objBajaj->dgnote_rate;
            if (trim($coverage) == 'BASIC_RISK') {
                $userRate = (!empty($isCheck->basic_user_rate) && $isCheck->basic_user_rate > 0) ? $isCheck->basic_user_rate :
                    $stndRateObj->basic_user_rate;
                $dgnoteRate = (!empty($isCheck->basic_rate) && $isCheck->basic_rate > 0) ? $isCheck->basic_rate :
                    $stndRateObj->basic_rate;
                $bajajRate = $objBajaj->basic_rate;
            }
        } else {
            if ($countryType == 'H' && $is_war_exclusion) {
                $countryType = 'P';
            }
            if (!empty($bajajGroup)) {
                $bajajRate = $this->getBajajGroupRate(
                    $company,
                    $commodityId,
                    $coverage,
                    $w2wCheck,
                    $isOdc,
                    $countryType
                );
            } else {
                $bajajRate = $this->getBajajRates(
                    $commodityId,
                    $isThirdCountry,
                    $coverage,
                    $w2wCheck,
                    $isOdc,
                    $countryType,
                    '',
                    $sumInsured
                );
            }
            $objUserRate = $this->getUserRateWithComparison(
                $company,
                $commodityId,
                $isThirdCountry,
                $isOdc,
                $w2wCheck,
                $coverage,
                $countryType
            );
            $userRate = !empty($objUserRate->user_rate) ? $objUserRate->user_rate : 0;

            $dgnoteRate = !empty($objUserRate->dgnote_rate) ? $objUserRate->dgnote_rate : 0;

            if (empty($bajajGroup) && $countryType == 'H') {
                if ($bajajRate < Yii::$app->params['bajajAllRiskHRARate']) {
                    $bajajRate = Yii::$app->params['bajajAllRiskHRARate'];
                }
                if ($userRate < Yii::$app->params['bajajAllRiskHRARate']) {
                    $userRate = Yii::$app->params['bajajAllRiskHRARate'];
                }

                if ($dgnoteRate < Yii::$app->params['bajajAllRiskHRARate']) {
                    $dgnoteRate = Yii::$app->params['bajajAllRiskHRARate'];
                }
            }
        }

        if ($bajajRate > $dgnoteRate || $bajajRate > $userRate) {
            $str = [
                'bajaj' => $bajajRate, 'dgnote' => $dgnoteRate, 'user' => $userRate, 'commodity' => $commodity, 'w2w' => $w2wCheck, 'isOdc' => $isOdc, 'is3rdCountry' => $isThirdCountry
            ];
            $this->saveLogs($company, 'Bajaj rate is greater than dgnote or user rate', $str);
            return false;
        }

        if ($dgnoteRate > $userRate) {
            $str = [
                'bajaj' => $bajajRate, 'dgnote' => $dgnoteRate, 'user' => $userRate, 'commodity' => $commodity, 'w2w' => $w2wCheck, 'isOdc' => $isOdc, 'is3rdCountry' => $isThirdCountry
            ];
            $this->saveLogs($company, 'DgNote rate is greater than user rate', $str);
            return false;
        }
        // print_R($dgnoteRate); print_R($userRate); die;

        if ($noOfReturn == 1) {
            $aRate = $userRate;
        } else {
            $aRate['user_rate'] = $userRate;
            $aRate['bajaj_rate'] = $bajajRate;
            $aRate['dgnote_rate'] = $dgnoteRate;
            $aRate['user_premium'] = $isCheck->user_premium;
            $aRate['dgnote_premium'] = $isCheck->dgnote_premium;
        }
        return $aRate;
    }

    private function removeCommaFromAmount($amount)
    {
        return str_replace(",", "", $amount);
    }

    private function getDgnoteRate($commodityId, $w2wCheck = 0, $isOdc = 0, $coverage = '')
    {
        $rateMatrix = new \common\models\RateMatrix();
        $odcRate = $rateMatrix->getODCRateByCommodity($commodityId, $coverage);
        if ($isOdc && $odcRate > 0) {
            return $odcRate;
        } else {
            if ($w2wCheck == 0) {
                return $rateMatrix->getDgnoteRateByCommodity($commodityId);
            } else {
                return $rateMatrix->getW2WRateByCommodity($commodityId);
            }
        }
    }

    private function setPolicyDetail($policyDetail, $quoteObj, $user_id) {
        $policyModel = new \common\models\Policy();
        $policyModel->policy_number = $policyDetail['policy_no'];
        $policyModel->policy_issue_date = date('Y-m-d');
        $policyModel->policy_owner = $quoteObj['institution_name'];
        $policyModel->provider_id = 1;
        $policyModel->status = 1;
        $policyModel->created_at = date("Y-m-d H:i:s");
        $policyModel->created_by = $user_id;

        if($policyModel->save()) {
            return $policyModel;
        } else {
            return false;
        }
    }

    private function setTransaction($user_id, $quoteObj, $setPolicyDetail) {
        $transactionModel = new \common\models\DgnoteTransaction();
        $transactionModel->user_id = $user_id;
        $transactionModel->product_id = 1;
        $transactionModel->insurance_type_id = 2;
        $transactionModel->transaction_id = "aaaaaaaaaaaaa";
        $transactionModel->quote_id = $quoteObj['id'];
        $transactionModel->provider_id = 1;

        $transactionModel->policy_id = $setPolicyDetail->id;
        $transactionModel->start_date = date('Y-m-d H:i:s');
        $transactionModel->status = 'success';
        $transactionModel->policy_status = 'success';
        $transactionModel->save('false'); 
    }

    private function generateInvoice($quoteObj, $user_id) {
        $objInvoice = new \common\models\DgnoteInvoice();
        $objInvoice->product_id = 1;
        $objInvoice->relation_id = $quoteObj['id'];
        $objInvoice->billing_party = $quoteObj['party_name'];
        $objInvoice->billing_address = $quoteObj['billing_address'];
        $objInvoice->billing_city = $quoteObj['billing_city'];
        $objInvoice->billing_pincode = $quoteObj['billing_pincode'];
        $objInvoice->billing_gstin = $quoteObj['gstin'];
        $objInvoice->total_inv_amnt = round($quoteObj['total_premium']);
        $objInvoice->created_by = $user_id;
        $objInvoice->created_at = date('Y-m-d h:i:s');
        $objInvoice->modified_at = date('Y-m-d h:i:s');

        if($objInvoice->save()) {
            return $objInvoice;
        } else {
            return false;
        }
    }

    private function setInvoiceDetail($quoteObj, $objInvoice, $get_gst_serialize, $data) {
        $sac_code = \common\models\SacCode::find()->where(['tax_type' =>
        'INSURANCE'])->one();

        $invoiceDetails = new \common\models\InvoiceDetails();
        $invoiceDetails->inv_id = $objInvoice->id;
        $invoiceDetails->product_id = 1;
        if (!empty($sac_code->description)) {
            $desc = " - " . $sac_code->description;
        } else {
            $desc = '';
        } 

        $quoteObj->invoice_id = $objInvoice->id;
        $quoteObj->update(false);

        $original_array = unserialize($get_gst_serialize);
        $invoiceDetails->acc_head = $sac_code->invoice_head . $desc;
        $invoiceDetails->sgst_rate = $original_array['sgst_rate'];
        $invoiceDetails->cgst_rate = $original_array['cgst_rate'];
        $invoiceDetails->igst_rate = $original_array['igst_rate'];
        $invoiceDetails->sgst_amt = $original_array['sgst'];
        $invoiceDetails->cgst_amt = $original_array['cgst'];
        $invoiceDetails->igst_amt = $original_array['igst'];
        $invoiceDetails->charge_type = 'Invoiced';
        $invoiceDetails->sac_code = $sac_code->sac_code;
        $invoiceDetails->created_at = date('Y-m-d H:i:s');
        $invoiceDetails->modified_at = date('Y-m-d H:i:s');
        $invoiceDetails->amount = $data['premium'];
        $invoiceDetails->tot_amount = $data['total_premium'];
        $invoiceDetails->save();
    }

    private function insertPurchaseData($user_id, $quoteObj, $generateInvoice) { 

        $paymentModeType = \common\models\PaymentMode::findOne(['name' => "Offline"]);
        $paymentTypePurchase = \common\models\PaymentType::findOne(['name' => "Purchase"]);

        $paymentPurchaseModal = new \common\models\DgnotePayment();
        $paymentPurchaseModal->product_id = 1;
        $paymentPurchaseModal->user_id = $user_id;
        $paymentPurchaseModal->payment_gateway_id = 1;
        $paymentPurchaseModal->payment_type_id = $paymentTypePurchase->id;  //purchase
        $paymentPurchaseModal->payment_mode_id = $paymentModeType->id;  //purchase
        $paymentPurchaseModal->invoice_number = $quoteObj['id'];
        $paymentPurchaseModal->transaction_date = date('Y-m-d H:i:s');
        $paymentPurchaseModal->payment_amount = $quoteObj['total_premium'];
        $paymentPurchaseModal->status = 'success';
        $paymentPurchaseModal->invoice_id = $generateInvoice->id;
    } 

    private function insertPaymentData($company_id, $quoteObj, $setPolicyDetail, $generateInvoice, $user_id) {
        $companyObj = \common\models\DgnoteCompanies::findOne($company_id);
        if ($companyObj->insurance_tds > 0) {

            $tdsAmount = round($quoteObj['premium']*$companyObj->insurance_tds/100);
            $aPolicy = \common\models\Policy::findOne($setPolicyDetail->id);
            
            $certificatNo = str_pad((int) $aPolicy->certificate_no,5,"0",STR_PAD_LEFT);

            $tdsAgainst = !empty($generateInvoice->id) ? 'INV'.$generateInvoice->id : $certificatNo;

            $payment = new Payment();

            $payment->invoice_number = $quoteObj['id'];
            $payment->product_id = 1;
            $payment->user_id = $user_id;
            $payment->payment_gateway_id = 1;
            $payment->payment_type_id = 2;
            $payment->payment_mode_id = 5;
            $payment->transaction_date = date('Y-m-d H:i:s');
            $payment->payment_amount = $tdsAmount;
            $payment->payment_ref_no = rand() . time();
            $payment->created_at = date('Y-m-d H:i:s');
            $payment->modified_at = date('Y-m-d H:i:s');
            $payment->status = 'success';
            $payment->remark = "TDS Against: $tdsAgainst";
            $payment->invoice_id = $generateInvoice->id;
            $payment->save(false);
        }
    }

    private function insertBajajLedger($quoteObj, $bajaj_total_premium, $policyDetail) {
        $payment = new \frontend\insurance\models\BajajLedger();
        $payment->payment_type_id = 1;
        $payment->payment_mode_id = 5;
        $payment->invoice_number = $quoteObj['id'];
        $payment->transaction_date = date('Y-m-d H:i:s');
        $payment->payment_amount = $bajaj_total_premium;
        $payment->payment_ref_no = rand() . time();
        $payment->created_at = date('Y-m-d H:i:s');
        $payment->modified_at = date('Y-m-d H:i:s');
        $payment->status = "success";
        $payment->remark = "";
        $payment->policy_company_id = $policyDetail['id'];
        $payment->utr_number = '';
        $payment->si_amount = $quoteObj['sum_insured'];
        $payment->topup_type = '';
        $payment->save(false);
    }

    private function updateUserAccountBalance($data, $user_id, $policyDetail, $bajaj_total_premium){
        $action = "deduct";
        $userAccountBalance = new \common\models\UserAccountBalance();

        $totalAmount = $data['total_premium'];

        $userAccountBalance->updateAccountBalance($action, $user_id, $totalAmount);

        $userAccountBalance->addCompanyAccountBalance($policyDetail['id'], $bajaj_total_premium, 1);
    }

    private function insertDataBajajPremium($quoteObj, $bajaj_premium, $bajaj_sgst_amount, $bajaj_cgst_amount, $bajaj_igst_amount, $sgst_rate, $cgst_rate, $igst_rate, $data, $bajaj_total_premium, $dgnote_premium, $dgnote_sgst_amount, $dgnote_cgst_amount, $dgnote_igst_amount, $dgnote_total_premium, $net_premium, $sgst_amount, $cgst_amount, $igst_amount, $total_premium) {
        $aBajajPremium = new \common\models\DgnoteBajajPremium();
        $aBajajPremium->quote_id = $quoteObj['id'];

        // Bajaj calculation
        $aBajajCalulation['bajaj_premium'] = $bajaj_premium;
        $aBajajCalulation['bajaj_tax']['sgst'] = $bajaj_sgst_amount;
        $aBajajCalulation['bajaj_tax']['cgst'] = $bajaj_cgst_amount;
        $aBajajCalulation['bajaj_tax']['igst'] = $bajaj_igst_amount;
        $aBajajCalulation['bajaj_tax']['ncc_cess'] = 0;
        $aBajajCalulation['bajaj_tax']['sgst_rate'] = $sgst_rate;
        $aBajajCalulation['bajaj_tax']['cgst_rate'] = $cgst_rate;
        $aBajajCalulation['bajaj_tax']['igst_rate'] = $igst_rate;
        $aBajajCalulation['bajaj_tax']['ncc_cess_rate'] = 0;
        $aBajajCalulation['bajaj_rate'] = $data['bajaj_rate'];
        $aBajajCalulation['bajaj_total_amount'] = round($bajaj_total_premium);
        $aBajajPremium->bajaj_calculation = serialize($aBajajCalulation);
        
        //Dgnote calculation
        $aDgnoteCalulation['dgnote_premium'] = $dgnote_premium;
        $aDgnoteCalulation['dgnote_tax']['sgst'] = $dgnote_sgst_amount;
        $aDgnoteCalulation['dgnote_tax']['cgst'] = $dgnote_cgst_amount;
        $aDgnoteCalulation['dgnote_tax']['igst'] = $dgnote_igst_amount;
        $aDgnoteCalulation['dgnote_tax']['ncc_cess'] = 0;
        $aDgnoteCalulation['dgnote_tax']['sgst_rate'] = $sgst_rate;
        $aDgnoteCalulation['dgnote_tax']['cgst_rate'] = $cgst_rate;
        $aDgnoteCalulation['dgnote_tax']['igst_rate'] = $igst_rate;
        $aDgnoteCalulation['dgnote_tax']['ncc_cess_rate'] = 0;
        $aDgnoteCalulation['dgnote_rate'] = $data['dgnote_rate'];
        $aDgnoteCalulation['dgnote_total_amount'] = round($dgnote_total_premium);
        $aBajajPremium->dgnote_calculation = serialize($aDgnoteCalulation);
        
        //User Calculation
        $aUserCalulation['user_premium'] = $net_premium;
        $aUserCalulation['user_tax']['sgst'] = $sgst_amount;
        $aUserCalulation['user_tax']['cgst'] = $cgst_amount;
        $aUserCalulation['user_tax']['igst'] = $igst_amount;
        $aUserCalulation['user_tax']['ncc_cess'] = 0;
        $aUserCalulation['user_tax']['sgst_rate'] = $sgst_rate;
        $aUserCalulation['user_tax']['cgst_rate'] = $cgst_rate;
        $aUserCalulation['user_tax']['igst_rate'] = $igst_rate;
        $aUserCalulation['user_tax']['ncc_cess_rate'] = 0;
        $aUserCalulation['user_rate'] = $data['user_rate'];
        $aUserCalulation['user_total_amount'] = round($total_premium);
        $aBajajPremium->user_calculation = serialize($aUserCalulation);
        
        //credit note calculation
        
        $difference = $net_premium - $dgnote_premium;
        if($difference < 0) {
            $difference = 0;
        }

        $aCreditNoteCalculation['credit_note_premium'] = $difference;

        if(!empty($igst_amount)){
            $aCreditNoteCalculation['credit_note_tax']['igst'] =  $difference*18/100;
            $aCreditNoteCalculation['credit_note_tax']['sgst'] = '';
            $aCreditNoteCalculation['credit_note_tax']['cgst'] = '';
        } else{
            $aCreditNoteCalculation['credit_note_tax']['igst'] = '';
            $aCreditNoteCalculation['credit_note_tax']['sgst'] = $difference*9/100;
            $aCreditNoteCalculation['credit_note_tax']['cgst'] = $difference*9/100;
        }
        $aCreditNoteCalculation['credit_note_tax']['ncc_cess'] = 0;
        $aCreditNoteCalculation['credit_note_tax']['igst_rate'] = $igst_rate;
        $aCreditNoteCalculation['credit_note_tax']['sgst_rate'] = $sgst_rate;
        $aCreditNoteCalculation['credit_note_tax']['cgst_rate'] = $cgst_rate;
        $aCreditNoteCalculation['credit_note_tax']['ncc_cess_rate'] = 0;
        $aCreditNoteCalculation['credit_note_total_amount'] = round($difference
            +$aCreditNoteCalculation['credit_note_tax']['igst']+$aCreditNoteCalculation['credit_note_tax']['sgst']
            +$aCreditNoteCalculation['credit_note_tax']['cgst'],2);
        $aCreditNoteCalculation['credit_note_tds'] = round($difference*5/100,2);
        $aBajajPremium->credit_note_calculation = serialize($aCreditNoteCalculation);

        $aBajajPremium->bajaj_premium = $aBajajCalulation['bajaj_total_amount'];
        $aBajajPremium->created_at = date('Y-m-d H:i:s');
        $aBajajPremium->flag = 0;
        
        $aBajajPremium->save(false);
    }

    private function getBov($transitTypeId, $commodit)
    {
        $commodityModel = new \common\models\Commodity();
        $commodityId = $commodityModel->getIdByCommodity($commodit);
        if (!$commodityId) {
            return '';
        }
        $bovModel = new \common\models\Bov();
        $data = $bovModel->getBov($transitTypeId, $commodityId);
        return $data[0]['bov'];
    } 
}
