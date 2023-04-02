<?php

namespace frontend\insurance\controllers;

use Yii;
use yii\web\Controller;
use PHPExcel_IOFactory;
use PhpOffice\PhpSpreadsheet\IOFactory;
use yii\filters\AccessControl;
use frontend\insurance\models\CargoForm;
use frontend\insurance\models\ContainerForm;
use common\models\DgnoteTrailerState;
use common\models\TransitMode;
use common\models\InsuranceContainer;
use yii\web\UploadedFile;
use \Mpdf\Mpdf;

/**
 * Site controller
 */
class QuotationController extends MainController
{

    public $insuranceproduct;
    public $producttypebycommodity;
    public $survyoeragent;
    public $transitmodebypackaging;
    public $coverageviacoveragmatrix;

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::className(),
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
        ];
    }

    public function beforeAction($action)
    {
        $this->enableCsrfValidation = false;
        return parent::beforeAction($action);
    }

    /**
     * Displays homepage.
     *
     * @return mixed
     */
    public function actionIndex()
    {
        //        $this->layout = false;
        //        return $this->render('index');
    }

    public function actionCargo($cargotype = 'import', $id = '')
    {
        $objBajajGroup = \common\models\CompanyPolicyCargoCertificate::checkBajajGroupExitOrNotWithInfo(Yii::$app->user->identity->company_id);
        if (isset($objBajajGroup) && $objBajajGroup->masterPolicy->bajaj_group) {
            Yii::$app->session->setFlash('error', 'UnAuthorized Access.');
            return $this->redirect(['user/policy']);
        }
        $accountbalance = new \common\models\UserAccountBalance();
        $balance = $accountbalance->getUserAccountBalance(Yii::$app->user->identity->id);
        if ($id) {
            $cargoModel = new CargoForm();
            $cargoModel->setCargo($id);
        } else {
            $cargoModel = new CargoForm();
        }
        $cargoModel->setScenario($cargotype);
        $backDate = $cargoModel->getBackAllowDate(
            $objBajajGroup,
            Yii::$app->params['allowBackDays']
        );
        // try { 
        Yii::$app->session->removeFlash('success');
        $objUsr = $this->getUserDetailByCompanyId(Yii::$app->user->identity->company_id);
        if (!isset($objUsr->pincode)) {
            Yii::$app->getSession()->addFlash('error', 'There is some issue.Please contact with admin');
            return $this->redirect(['user/policy']);
        }
        //            $flagSez = $this->checkSEZ();
        $objSez = $this->getSEZ();
        $objCargoCertificate = \common\models\CompanyPolicyCargoCertificate::checkBajajGroupExitOrNotWithInfo(Yii::$app->user->identity->company_id);
        if ($cargoModel->load(Yii::$app->request->post())) {

            $aRequest = Yii::$app->request->post();
            $cargotype = strtolower($aRequest['CargoForm']['transit_type']);

            $isDraft = isset($aRequest['Draft']) ? 1 : 0;

            if (!$this->checkCompanyCredit($balance, $aRequest['CargoForm']['total_premium'], $isDraft)) {
                Yii::$app->session->setFlash('error', 'Payment is declined due to insufficient credit limit, please contact DgNote Administrator!');
            } else {
                $cuntryflag = true;
                if (isset($aRequest['CargoForm']['country_offline']) && $aRequest['CargoForm']['country_offline'] == 1) {
                    if ($aRequest['CargoForm']['transit_type'] == 'Import') {
                        $country = $aRequest['CargoForm']['origin_country'];
                    } else {
                        $country = $aRequest['CargoForm']['destination_country'];
                    }
                    if (!\common\models\Country::checkSanctionAndHRACountryByName($country)) {
                        $cuntryflag = false;
                        Yii::$app->session->setFlash('error', 'There is some issue to selected country.Please select again!');
                    }
                }
                if ($cuntryflag) {
                    $checSumInsured = $aRequest['CargoForm']['invoice_amount'];
                    //                    if ($checSumInsured >= 20000000 && 
                    //                            $aRequest['CargoForm']['transit_type']=='Inland') {
                    //                        Yii::$app->session->setFlash('error', 'Sum insured should not be greater than '
                    //                                . 'Rs. 2 Crores. Please contact DgNote Team at contact@dgnote.com '
                    //                                . 'or +91-22-22652123 to buy offline policy.');
                    //                        return $this->redirect("cargo?cargotype=$cargotype");
                    //                    }
                    unset($aRequest['CargoForm']['comma_sum_insured']);
                    $this->saveUserContactDetails(
                        $aRequest['CargoForm']['institution_name'],
                        $aRequest['CargoForm']['address'],
                        $aRequest['CargoForm']['city'],
                        $aRequest['CargoForm']['state'],
                        $aRequest['CargoForm']['pincode'],
                        $aRequest['CargoForm']['gstin'],
                        $aRequest['CargoForm']['party_name'],
                        $aRequest['CargoForm']['billing_city'],
                        $aRequest['CargoForm']['billing_state'],
                        $aRequest['CargoForm']['billing_address'],
                        $aRequest['CargoForm']['billing_pincode']
                    );
                    $productModel = new \common\models\InsuranceProduct();
                    $transitTypeModel = new \common\models\TransitType();
                    $transitTypeId = $transitTypeModel->getIdByTransitType($cargoModel->transit_type);
                    $transitModeModel = new \common\models\TransitMode();
                    $transitModeId = $transitModeModel->getIdByTransitMode($cargoModel->transit_mode);
                    $aInsuranceProduct = $productModel->getProductCodeByMatrix(
                        CargoForm::NON_CONTAINER_PRODUCT_ID,
                        $transitTypeId,
                        $transitModeId
                    );
                    $cargoModel->branch = Yii::$app->user->identity->branch;
                    $cargoModel->company_id = Yii::$app->user->identity->company_id;
                    $cargoModel->product_code = $aInsuranceProduct['code'];

                    $cargoModel->valuation_basis = ($aRequest['CargoForm']['valuation_basis'] == 'Terms Of Sale') ? 'TOS' : $aRequest['CargoForm']['valuation_basis'];
                    $cargoModel->contact_name = Yii::$app->user->identity->first_name . " " . Yii::$app->user->identity->last_name;
                    $cargoModel->mobile = Yii::$app->user->identity->mobile;
                    $cargoModel->country = Yii::$app->user->identity->country;
                    $cargoModel->total_premium = $this->removeCommaFromAmount($aRequest['CargoForm']['total_premium']);

                    $cargoModel->premium = $this->removeCommaFromAmount($aRequest['CargoForm']['premium']);
                    $cargoModel->gstin = $aRequest['CargoForm']['gstin'];
                    $cargoModel->pan = \yii::$app->gst->getPanFromGSTNo($cargoModel->gstin);
                    $cargoModel->pincode = $aRequest['CargoForm']['pincode'];
                    $cargoModel->w2w = isset($aRequest['CargoForm']['w2w']) ? $aRequest['CargoForm']['w2w'] : 0;
                    $cargoModel->user_detail = (isset($aRequest['user_detail']) && $aRequest['user_detail'] == 'on') ? 1 : 0;
                    $cargoModel->billing_detail = isset($aRequest['billing_detail'][0]) ? $aRequest['billing_detail'][0] : 0;
                    $cargoModel->is_sez = isset($aRequest['CargoForm']['is_sez']) ? $aRequest['CargoForm']['is_sez'] : 0;
                    $sezFlag = false;
                    $cargoModel->service_tax_amount = $this->removeCommaFromAmount($aRequest['CargoForm']['service_tax_amount']);
                    if ($objSez->is_sez == 2 && $cargoModel->billing_detail == 2 && $cargoModel->is_sez == 1) {
                        $cargoModel->service_tax_amount = 0;
                        $sezFlag = true;
                    } elseif ($objSez->is_sez == 1 && $cargoModel->billing_detail == 1 && $cargoModel->is_sez == 1) {
                        $cargoModel->service_tax_amount = 0;
                        $sezFlag = true;
                    }






                    //                    $sezFlag = true;
                    //                    if($cargoModel->is_sez==1 && $cargoModel->billing_detail==1 && $objSez->is_sez==1){
                    //                        $cargoModel->service_tax_amount = $this->removeCommaFromAmount($aRequest['CargoForm']['service_tax_amount']);
                    //                        $sezFlag = false;
                    //                    }
                    //                    elseif($cargoModel->is_sez==0 && $cargoModel->billing_detail==2 && $objSez->is_sez==2)
                    //                    {
                    //                        $sezFlag = false;
                    //                        $cargoModel->service_tax_amount = $this->removeCommaFromAmount($aRequest['CargoForm']['service_tax_amount']);
                    //                    }

                    //                    if ($flagSez && $cargoModel->billing_detail==1) {
                    //                        $cargoModel->service_tax_amount = 0 ;
                    //                        $cargoModel->is_sez = $flagSez;
                    //                    } else{
                    //                        $cargoModel->service_tax_amount = $this->removeCommaFromAmount($aRequest['CargoForm']['service_tax_amount']);
                    //                    }
                    $commodityModel = new \common\models\Commodity();

                    $commodityId = $commodityModel->getIdByCommodity($aRequest['CargoForm']['commodity']);
                    $companyId = Yii::$app->user->identity->company_id;
                    if (\common\models\CompanyPolicyCargoCertificate::checkBajajGroupExitOrNot($companyId)) {
                        $objGroup = \common\models\CompanyPolicyCargoCertificate::checkBajajRateGroupForPolicy($commodityId, $companyId);
                        if (!$objGroup) {
                            // send mail for admin that policy is not mapped for that company
                            Yii::$app->session->setFlash('error', 'Commodity not configure please contact DgNote Administrator!');
                            return $this->redirect("cargo?cargotype=$cargotype");
                        } else {
                            $cargoModel->bajaj_group = $objGroup->masterPolicy->bajaj_group;
                        }
                    }
                    //                        if($cargoModel->commodity=='COM50'){
                    //                            $cargoModel->packing = 'PCK10';
                    //                        }
                    $cmnRtMdl = $this->isCertificate(Yii::$app->user->identity->company_id, 2, $commodityId);
                    if ($cmnRtMdl) {
                        //                    if(!\common\models\CompanyPolicyCargoCertificate::
                        //                            checkMasterPolicyEndDate(Yii::$app->user->identity->company_id,2)){
                        //                        Yii::$app->session->setFlash('error', 'Certificate cannot be issued. Please contact DgNote Administrator!');
                        //                            return $this->redirect("cargo?cargotype=$cargotype");
                        //                    }
                        $coverage = '';
                        if (!empty($cargoModel->coverage)) {
                            $aCoverage = explode('+', $cargoModel->coverage);
                            $coverage = $aCoverage[0];
                        }
                        $cargoModel->dgnote_commission = $this->getDgnoteRate(
                            $commodityId,
                            $cargoModel->w2w,
                            $cargoModel->is_odc,
                            $coverage
                        );

                        if (
                            $aRequest['CargoForm']['transit_type'] == 'Export' ||
                            $aRequest['CargoForm']['transit_type'] == 'Import'
                        ) {
                            $cargoModel->destination_country = $aRequest['CargoForm']['destination_country'];
                            if (!empty($aRequest['CargoForm']['surveyor_city']) && $aRequest['CargoForm']['surveyor_city'] == 'NA') {
                                $cargoModel->surveyor_country = $cargoModel->destination_country;
                                $cargoModel->surveyor_address = $aRequest['CargoForm']['surveyor_address'];
                                $cargoModel->surveyor_agent = $aRequest['CargoForm']['surveyor_agent'];
                            } else {
                                $survAgentResutl = $this->getSurveyoragent(
                                    $aRequest,
                                    $commodityId,
                                    $cargoModel->destination_country,
                                    $transitTypeId,
                                    $aRequest['CargoForm']['terms_of_sale']
                                );
                                $cargoModel->surveyor_address = $survAgentResutl['surveyor_address'];
                                $cargoModel->surveyor_id = $survAgentResutl['surveyor_id'];
                                $cargoModel->surveyor_agent = $survAgentResutl['surveyor_agent'];
                                $cargoModel->surveyor_city = $survAgentResutl['surveyor_city'];
                            }
                        } else {
                            $cargoModel->surveyor_country = $cargoModel->destination_country;
                            $cargoModel->surveyor_address = $aRequest['CargoForm']['surveyor_address'];
                            $cargoModel->surveyor_agent = $aRequest['CargoForm']['surveyor_agent'];
                            $cargoModel->surveyor_id = $aRequest['CargoForm']['surveyor_id'];
                            $cargoModel->surveyor_city = $aRequest['CargoForm']['surveyor_city'];
                        }
                        $upload_invoice = $upload_packing_list = $upload_offline_format = '';
                        if ($aRequest['CargoForm']['is_offline'] != 0 || (isset($aRequest['CargoForm']['is_odc']) &&
                            $aRequest['CargoForm']['is_odc'] != 0)) {
                            $upload_invoice = \yii\web\UploadedFile::getInstance($cargoModel, 'upload_invoice');
                            $upload_packing_list = \yii\web\UploadedFile::getInstance($cargoModel, 'upload_packing_list');
                            $upload_offline_format = \yii\web\UploadedFile::getInstance($cargoModel, 'upload_offline_format');
                            $totFileSize = (isset($upload_invoice->size) ? $upload_invoice->size : 0) + (isset($upload_packing_list->size) ? $upload_packing_list->size : 0);
                            $fileSizeMb = $totFileSize / (1024 * 1024);
                            if ($fileSizeMb > '7.5') {
                                Yii::$app->session->setFlash('error', 'Total file size can not be greater than 7MB.');
                                return $this->redirect("cargo?cargotype=$cargotype");
                            }
                        }
                        //odc documents upload validation
                        if (isset($aRequest['CargoForm']['uploaded_odc_details']) && count($aRequest['CargoForm']['uploaded_odc_details']) > 0) {
                            $uploaded_odc_details = \yii\web\UploadedFile::getInstances($cargoModel, 'uploaded_odc_details');
                            $totFileSizeOdc = 0;
                            if (count($uploaded_odc_details) > 0) {
                                foreach ($uploaded_odc_details as $odc) {
                                    $totFileSizeOdc += (isset($odc->size) ? $odc->size : 0);
                                }
                            }
                            $fileSizeMbOdc = $totFileSizeOdc / (1024 * 1024);
                            if ($fileSizeMbOdc > '7.5') {
                                Yii::$app->session->setFlash('error', 'Uploaded ODC Details file size can not be greater than 7MB.');
                                return $this->redirect("cargo?cargotype=$cargotype");
                            }

                            // else{
                            //     $imagePdfPath = $cargoModel->saveOdcDetailsAsPdf($uploaded_odc_details);
                            // }
                        }

                        //survey documents upload validation
                        $survey_report = '';
                        if (isset($aRequest['CargoForm']['survey_report']) && count($aRequest['CargoForm']['survey_report']) > 0) {
                            $survey_report = \yii\web\UploadedFile::getInstances($cargoModel, 'survey_report');
                            $totFileSizeOdc = 0;
                            if (count($survey_report) > 0) {
                                foreach ($survey_report as $odc) {
                                    $totFileSizeOdc += (isset($odc->size) ? $odc->size : 0);
                                }
                            }
                            $fileSizeMbOdc = $totFileSizeOdc / (1024 * 1024);
                            if ($fileSizeMbOdc > '5') {
                                Yii::$app->session->setFlash('error', 'Uploaded Survey Details file size can not be greater than 5MB.');
                                return $this->redirect("cargo?cargotype=$cargotype");
                            }
                        }

                        // change invoice name as requirement
                        $upload_invoice_actual_name = '';
                        if ($upload_invoice) {
                            $upload_invoice_image_arr = explode('.', $upload_invoice->name);
                            $inv_no = $aRequest['CargoForm']['invoice_no'];
                            $upload_invoice_actual_name = $inv_no . '_Invoice.' . end($upload_invoice_image_arr);
                        }

                        $cargoModel->upload_invoice = $upload_invoice != '' ? $upload_invoice_actual_name : "";


                        // change invoice name as requirement
                        $upload_packing_list_actual_name = '';
                        if ($upload_packing_list) {
                            $upload_packing_list_actual_name_arr = explode('.', $upload_packing_list->name);
                            $inv_no = $aRequest['CargoForm']['invoice_no'];
                            $upload_packing_list_actual_name = $inv_no . '_Packing List.' . end($upload_packing_list_actual_name_arr);
                        }
                        $cargoModel->upload_packing_list = $upload_packing_list != '' ? $upload_packing_list_actual_name : "";


                        // change invoice name as requirement
                        $upload_offline_format_actual_name = '';
                        if ($upload_offline_format) {
                            $upload_offline_format_actual_name_arr = explode('.', $upload_offline_format->name);
                            $inv_no = $aRequest['CargoForm']['invoice_no'];
                            $upload_offline_format_actual_name = $inv_no . '_Offline Bajaj Format.' . end($upload_offline_format_actual_name_arr);
                        }
                        $cargoModel->upload_offline_format = $upload_offline_format != '' ? $upload_offline_format_actual_name : "";


                        if ($aRequest['CargoForm']['country_offline'] == 1) {
                            $cargoModel->is_offline = 1;
                        } elseif ($aRequest['CargoForm']['is_odc'] == 1) {
                            $cargoModel->is_offline = 1;
                        } elseif ($cargoModel->sum_insured >= \Yii::$app->params['maxInsurancePremium']) {
                            $cargoModel->is_offline = 1;
                            $cargoModel->cr_2_offline = 1;
                        } elseif ($aRequest['CargoForm']['back_date'] == 1) {
                            $cargoModel->is_offline = 1;
                        } else {
                            $cargoModel->is_offline = 0;
                            $cargoModel->cr_2_offline = 0;
                            $aRequest['CargoForm']['is_offline'] = 0;
                            $aRequest['CargoForm']['is_offline'] = 0;
                        }
                        $currentD = date('Y-m-d');
                        $ct = date('Y-m-d', strtotime($aRequest['CargoForm']['coverage_start_date']));
                        if ($currentD > $ct) {
                            $cargoModel->is_offline = 1;
                            $cargoModel->back_date = 1;
                        }

                        if ($cargoModel->validate()) {
                            $validationObj = new \frontend\insurance\components\MasterDataValidatonComponent();
                            $aError = $validationObj->checkServerValidation($cargoModel, 'noncontainer', $cmnRtMdl);

                            if ($aError['status']) {
                                if ($sezFlag) {
                                    $serailze =
                                        \yii::$app->gst->getSeralizedGSTWithOutRound(
                                            $cargoModel->premium,
                                            $cargoModel->total_premium,
                                            $cargoModel->billing_state
                                        );
                                    $aUnSerailize = unserialize($serailze);
                                    $aUnSerailize['igst'] = 0;
                                    $aUnSerailize['sgst'] = 0;
                                    $aUnSerailize['cgst'] = 0;
                                    $cargoModel->service_tax_attributes = serialize($aUnSerailize);
                                } else {
                                    $cargoModel->service_tax_attributes =
                                        \yii::$app->gst->getSeralizedGSTWithOutRound($cargoModel->premium, $cargoModel->total_premium, $cargoModel->billing_state);
                                }
                                $cargoModel->is_draft = $isDraft;
                                //                            $cargoModel->service_tax_attributes = 
                                //                                    \yii::$app->gst->getSeralizedGSTWithOutRound($cargoModel->premium,$cargoModel->total_premium,$cargoModel->gstin);
                                if ($quote = $cargoModel->save($upload_invoice, $upload_packing_list, $upload_offline_format)) {
                                    Yii::$app->session->set('user.quote_id', $quote->id);
                                    if ($uploaded_odc_details) {
                                        //upload odc images
                                        $imagePdfPath = $cargoModel->saveOdcDetailsAsPdf($uploaded_odc_details, $quote);
                                        $quoteOdc = \common\models\Quotation::findOne($quote->id);
                                        $quoteOdc->uploaded_odc_details = $imagePdfPath;
                                        $quoteOdc->save([false, 'uploaded_odc_details']);
                                    }

                                    if ($survey_report) {
                                        //upload survey report images
                                        $imagePdfPath = $cargoModel->saveSurveyReportAsPdf($survey_report, $quote);
                                        $quoteOdc = \common\models\Quotation::findOne($quote->id);
                                        $quoteOdc->survey_report = $imagePdfPath;
                                        $quoteOdc->is_offline = 1;
                                        $quoteOdc->is_survey = 1;
                                        $quoteOdc->save([false, 'survey_report', 'is_offline', 'is_survey']);
                                    }

                                    if ($isDraft) {
                                        Yii::$app->session->setFlash('success', 'Your policy has been drafted successfully.');
                                        return $this->redirect(['user/policy']);
                                    }
                                    return $this->redirect("confirmation");
                                } else {
                                    Yii::$app->session->setFlash('error', 'There is some error please try again.');
                                    return $this->redirect("cargo?cargotype=$cargotype");
                                }
                            } else {
                                Yii::$app->session->setFlash('error', $aError['error']);
                                return $this->redirect("cargo?cargotype=$cargotype");
                            }
                        } else {
                            $aError = $cargoModel->getErrors();
                            foreach ($aError as $key => $value) {
                                Yii::$app->session->setFlash('error', $value[0]);
                                break;
                            }

                            $rUrl = isset($id) ? "cargo?cargotype=$cargotype&id=$id" : "cargo?cargotype=$cargotype";
                            //                    return $this->redirect($rUrl);
                        }
                    } else {
                        Yii::$app->session->setFlash('error', 'Commodity Rates are not configure, please contact DgNote Administrator!');
                    }
                }
            }
        }
        // } catch (\Exception $e) {
        //     \Yii::$app->commonMailSms->sendMailAtException(''
        //     ,"Issue in Cargo Certificate for".Yii::$app->user->identity->company_id
        //     ,$e->getMessage());
        // }



        //        $objUser = $cargoModel->getUserFlag(Yii::$app->user->identity->company_id);
        //        $backDate = Yii::$app->params['allowBackDays'];
        return $this->render($cargotype, [
            'model' => $cargoModel,
            'balance' => $balance,
            'cargotype' => $cargotype,
            'id' => $id,
            'backDate' => $backDate,
            //            'objUser' => $objUser
            'flagSez' => '',
            'objSez' => $objSez,
        ]);
    }

    /**
     * Ajax function to get transit mode
     * @param string $type
     * @return json
     */
    public function actionTransitmode()
    {
        //        try {
        if (Yii::$app->request->isAjax) {
            $aRequest = Yii::$app->request->post();
            $isOdc = isset($aRequest['isOdc']) ? $aRequest['isOdc'] : 0;
            $insuranceType = 'container';
            $transitTypeId = 3;
            $productId = ContainerForm::INSURANCE_PRODUCT_TYPE_ID;
            $termOfSale = null;
            if ($aRequest['type'] == 'noncontainer') {
                $insuranceType = 'Non-Container';
                $transitTypeModel = new \common\models\TransitType();
                $transitTypeId = $transitTypeModel->getIdByTransitType($aRequest['transitType']);
                $productId = CargoForm::NON_CONTAINER_PRODUCT_ID;
                $termOfSale = isset($aRequest['termOfSale']) ? $aRequest['termOfSale'] : "";
            }
            $transitModeModel = new TransitMode();
            $data = $transitModeModel->getTransitModeBySaleTermAndTransitType(
                $transitTypeId,
                $termOfSale,
                $productId,
                $isOdc
            );

            $mdlCom = new \common\models\Commodity();
            $aCom = $mdlCom->getCommodityBycode($aRequest['commodity']);

            if (count($aCom) > 0 && trim($aCom[0]['coverage_type']) == "BASIC_RISK") {
                if (count($data) == 6) {
                    unset($data[4]);
                    unset($data[5]);
                }
            }

            if ($aRequest['commodity'] == 'COM75') {
                $data = array(
                    0 => [
                        'id' => 2,
                        'name' => 'Air'
                    ]
                );
            }
            if ($aRequest['commodity'] == 'COM10') {
                $data1 = array(
                    0 => [
                        'id' => 1,
                        'name' => 'Sea'
                    ]
                );
                $data = array_merge($data, $data1);
            }

            if(sizeof($aCom) > 0) {
                $CommTrans = new \common\models\CommodityTransit();
                $getComTran = $CommTrans->getCommodityTransit($aCom['0']['id']);
                if(sizeof($getComTran) > 0) {
                    $data = array(
                        0 => [
                            'id' => $getComTran[0]['id'],
                            'name' => $getComTran[0]['name']
                        ]
                    );
                }
            } 

            \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
            return [
                'transitMode' => $data,
            ];
        }
        //        } catch (\Exception $ex) {
        //            
        //        }
    }

    public function actionCommodity()
    {
        try {
            if (Yii::$app->request->isAjax) {
                $aRequest = Yii::$app->request->post();
                $insuranceType = 'container';
                if ($aRequest['type'] == 'noncontainer') {
                    $insuranceType = 'Non-Container';
                }

                $query = \common\models\Commodity::find();
                $query->select(['id' => 'mi_comodity.code', 'name' => 'mi_comodity.name'])
                    ->distinct()
                    ->joinWith(['producttypebycommodity'])
                    ->where(["mi_insurance_product_type.name" => $insuranceType])
                    ->andWhere(['mi_comodity.status' => 1]);
                $query->orderBy('mi_comodity.name');
                $command = $query->createCommand();
                $data = $command->queryAll();
                \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
                return [
                    'commodity' => $data,
                ];
            }
        } catch (\Exception $ex) {
        }
    }

    public function actionContainer($id = '')
    {
        $objBajajGroup = \common\models\CompanyPolicyCargoCertificate::checkBajajGroupExitOrNotWithInfo(Yii::$app->user->identity->company_id);
        if (isset($objBajajGroup) && $objBajajGroup->masterPolicy->bajaj_group) {
            Yii::$app->session->setFlash('error', 'UnAuthorized Access.');
            return $this->redirect(['user/policy']);
        }
        $accountbalance = new \common\models\UserAccountBalance();
        $balance = $accountbalance->getUserAccountBalance(Yii::$app->user->identity->id);

        if ($id) {
            $contQuotModel = new ContainerForm();
            $contQuotModel->setContainer($id);
        } else {
            $contQuotModel = new ContainerForm();
        }
        $contQuotModel->company_id = \Yii::$app->user->identity->company_id;
        $container = new InsuranceContainer();
        $uploadModel = new \frontend\insurance\models\UploadFile();
        // check company exist in company rate.. Yii::$app->user->identity->company_id;
        $cmnRtMdl = $this->isCertificate(Yii::$app->user->identity->company_id, 1, '');
        $backDate = $contQuotModel->getBackAllowDate(0);
        $objSez = $this->getSEZ();
        if ($cmnRtMdl) {


            $connection = \Yii::$app->db;
            $transaction = $connection->beginTransaction();
            try {
                Yii::$app->session->removeFlash('success');
                $objUsr = $this->getUserDetailByCompanyId(Yii::$app->user->identity->company_id);
                if (!isset($objUsr->pincode)) {
                    Yii::$app->getSession()->addFlash('error', 'There is some issue.Please contact with admin');
                    return $this->redirect(['user/policy']);
                }

                if (
                    $contQuotModel->load(Yii::$app->request->post()) &&
                    $container->load(Yii::$app->request->post())
                ) {
                    $aRequest = Yii::$app->request->post();

                    $isDraft = isset($aRequest['Draft']) ? 1 : 0;
                    $commodityModel = new \common\models\Commodity();
                    $commodityId = $commodityModel->getIdByCommodity($contQuotModel->commodity);
                    $transitTypeModel = new \common\models\TransitType();
                    $transitTypeId = $transitTypeModel->getIdByTransitType(ContainerForm::TRANSIT_TYPE);
                    $transitModeModel = new \common\models\TransitMode();
                    $transitModeId = $transitModeModel->getIdByTransitMode($contQuotModel->transit_mode);
                    $objPackaging = new \common\models\Packaging();
                    $data = $objPackaging
                        ->getPackaging($commodityId, $transitTypeId, $transitModeId);
                    if (!$this->checkCompanyCredit($balance, $aRequest['ContainerForm']['total_premium'], $isDraft)) {
                        Yii::$app->session->setFlash('error', 'Payment is declined due to insufficient credit limit, please contact DgNote Administrator!');
                    } elseif (!isset($data[0]['code'])) {
                        Yii::$app->session->setFlash('error', 'Issue related to packaing, please contact DgNote Administrator!');
                    } else {

                        $checSumInsured = $aRequest['ContainerForm']['sum_insured'];
                        $amount = \Yii::$app->params['maxInsurancePremiumShrtMsg'];
                        if ($checSumInsured > \Yii::$app->params['maxInsurancePremium']) {
                            Yii::$app->session->setFlash('error', "Sum insured should not be greater than "
                                . "Rs. $amount Crores. Please contact DgNote Team at contact@dgnote.com "
                                . "or +91-22-22652123 to buy offline policy.");
                            return $this->redirect("container");
                        }
                        //                        if(!\common\models\CompanyPolicyNo::
                        //                                checkMasterPolicyEndDateForContainer()){
                        //                            Yii::$app->session->setFlash('error', 'Certifucate cannot be issued. Please contact DgNote Administrator!');
                        //                                return $this->redirect("container");
                        //                        }

                        $this->saveUserContactDetails(
                            $aRequest['ContainerForm']['institution_name'],
                            $aRequest['ContainerForm']['address'],
                            $aRequest['ContainerForm']['city'],
                            $aRequest['ContainerForm']['state'],
                            $aRequest['ContainerForm']['pincode'],
                            $aRequest['ContainerForm']['gstin'],
                            $aRequest['ContainerForm']['party_name'],
                            $aRequest['ContainerForm']['billing_city'],
                            $aRequest['ContainerForm']['billing_state'],
                            $aRequest['ContainerForm']['billing_address'],
                            $aRequest['ContainerForm']['billing_pincode']
                        );
                        $contQuotModel->branch = Yii::$app->user->identity->branch;
                        $contQuotModel->contact_name = Yii::$app->user->identity->first_name . "" . Yii::$app->user->identity->last_name;
                        $contQuotModel->mobile = Yii::$app->user->identity->mobile;
                        $contQuotModel->company_id = Yii::$app->user->identity->company_id;
                        $contQuotModel->country = Yii::$app->user->identity->country;
                        $contQuotModel->container_movement = ContainerForm::CONT_MOVE_SINGLE;
                        $contQuotModel->total_premium = $this->removeCommaFromAmount($aRequest['ContainerForm']['total_premium']);


                        $contQuotModel->premium = !empty($aRequest['ContainerForm']['premium']) ? $this->removeCommaFromAmount($aRequest['ContainerForm']['premium']) : '';
                        $contQuotModel->gstin = $aRequest['ContainerForm']['gstin'];
                        $contQuotModel->gstin_sez = $aRequest['ContainerForm']['gstin_sez'];
                        $contQuotModel->pan = \yii::$app->gst->getPanFromGSTNo($contQuotModel->gstin);
                        $contQuotModel->pincode = $aRequest['ContainerForm']['pincode'];
                        $contQuotModel->user_detail = (isset($aRequest['user_detail']) && $aRequest['user_detail'] == 'on') ? 1 : 0;
                        $contQuotModel->billing_detail = isset($aRequest['billing_detail'][0]) ? $aRequest['billing_detail'][0] : 0;
                        $contQuotModel->is_sez = isset($aRequest['ContainerForm']['is_sez']) ? $aRequest['ContainerForm']['is_sez'] : 0;
                        $contQuotModel->packing = isset($data[0]['code']) ? $data[0]['code'] : '';

                        $sezFlag = false;
                        $contQuotModel->service_tax_amount = $this->removeCommaFromAmount($aRequest['ContainerForm']['service_tax_amount']);
                        if (
                            $objSez->is_sez == 2 && $contQuotModel->billing_detail == 2 &&
                            $contQuotModel->is_sez == 1
                        ) {
                            $contQuotModel->service_tax_amount = 0;
                            $sezFlag = true;
                        } elseif (
                            $objSez->is_sez == 1 && $contQuotModel->billing_detail == 1 &&
                            $contQuotModel->is_sez == 1
                        ) {
                            $contQuotModel->service_tax_amount = 0;
                            $sezFlag = true;
                        }
                        $commodityModel = new \common\models\Commodity();

                        if ($contQuotModel->validate($contQuotModel->getAttributes())) {
                            $contQuotModel->dgnote_commission = $this->getDgnoteRate($commodityModel->getIdByCommodity($aRequest['ContainerForm']['commodity']));
                            $validationObj = new \frontend\insurance\components\MasterDataValidatonComponent();
                            $aError = $validationObj->checkServerValidation($contQuotModel, 'container', $cmnRtMdl);
                            if ($aError['status']) {

                                if ($contQuotModel->validate()) {
                                    if ($sezFlag) {
                                        $serailze =
                                            \yii::$app->gst->getSeralizedGSTWithOutRound(
                                                $contQuotModel->premium,
                                                $contQuotModel->total_premium,
                                                $contQuotModel->billing_state
                                            );
                                        $aUnSerailize = unserialize($serailze);
                                        $aUnSerailize['igst'] = 0;
                                        $aUnSerailize['sgst'] = 0;
                                        $aUnSerailize['cgst'] = 0;
                                        $contQuotModel->service_tax_attributes = serialize($aUnSerailize);
                                    } else {
                                        $contQuotModel->service_tax_attributes =
                                            \yii::$app->gst->getSeralizedGSTWithOutRound(
                                                $contQuotModel->premium,
                                                $contQuotModel->total_premium,
                                                $contQuotModel->billing_state
                                            );
                                    }
                                    $contQuotModel->is_draft = $isDraft;
                                    $containerNo = $aRequest['InsuranceContainer']['container_no'];
                                    if ($containerNo) {
                                        $userId = \yii::$app->user->id;
                                        $count = $contQuotModel->checkUniqueContainerNoForCurrentDay($userId, $containerNo);
                                        if ($count >= 1) {
                                            return $this->redirect(['user/policy']);
                                        } else {
                                            if ($quote = $contQuotModel->save()) {
                                                $aErrr = $container->add($quote->id);
                                                if ($isDraft) {
                                                    $transaction->commit();
                                                    Yii::$app->session->setFlash('success', 'Your policy has been drafted successfully.');
                                                    return $this->redirect(['user/policy']);
                                                }
                                                if (count($aErrr) == 0) {
                                                    $transaction->commit();
                                                    Yii::$app->session->set('user.quote_id', $quote->id);
                                                    $this->redirect("confirmation");
                                                } else {
                                                    Yii::$app->session->setFlash('error', $aErrr['err']);
                                                    $transaction->rollBack();
                                                    return $this->redirect('container');
                                                }
                                            } else {
                                                $error = $contQuotModel->getErrors();
                                                if ($error) {
                                                    foreach ($error as $key => $value) {
                                                        Yii::$app->session->setFlash('error', $error[$key][0]);
                                                    }
                                                }
                                            }
                                        }
                                    }
                                } else {
                                    $error = $contQuotModel->getErrors();
                                    if ($error) {
                                        foreach ($error as $key => $value) {
                                            Yii::$app->session->setFlash('error', $error[$key][0]);
                                        }
                                    }
                                }
                            } else {
                                Yii::$app->session->setFlash('error', $aError['error']);
                                return $this->redirect('container');
                            }
                        } else {
                            Yii::$app->session->setFlash('error', $contQuotModel->getErrors());
                            return $this->redirect('container');
                        }
                    }
                } else {
                }
            } catch (\Exception $e) {
                $transaction->rollBack();
                //                echo "<pre>";   print_r($e);    die;
            }
        } else {
            Yii::$app->session->setFlash('error', 'Commodity Rates are not configure, '
                . 'please contact DgNote!.');
        }

        $objUser = $contQuotModel->getUserFlag(Yii::$app->user->identity->company_id);
        //        $backDate = Yii::$app->params['allowBackDays'];
        return $this->render('container', [
            'model' => $contQuotModel,
            'container' => $container,
            'balance' => $balance,
            'uploadModel' => $uploadModel,
            'cargotype' => '',
            'companyRt' => $cmnRtMdl,
            'objUser' => $objUser,
            'backDate' => $backDate,
            'objSez' => $objSez,
            'id' => $id,
        ]);
    }

    /**
     * For origin and destination country list
     * @param string $transitType
     * @return json
     */
    public function actionToandfromcountry()
    {
        try {
            if (Yii::$app->request->isAjax) {
                $aRequest = Yii::$app->request->post();
                $company_id = $commodityId = '';
                $model = new \common\models\Country();
                $isSanction = isset($aRequest['isSanction']) ? true : false;

                if (isset($aRequest['transitType']) && $aRequest['transitType'] != 'Inland') {
                    $commodityCode = $aRequest['commodity'];
                    $commodityModel = new \common\models\Commodity();
                    $commodityId = $commodityModel->getIdByCommodity($aRequest['commodity']);
                    $aReturn = array();
                    $company_id = Yii::$app->user->identity->company_id;
                }

                switch ($aRequest['transitType']) {
                    case 'Import':
                        $aReturn = $model->getImportcountry($company_id, $commodityId, $isSanction);
                        break;
                    case 'Export':
                        $aReturn = $model->getExportcountry($company_id, $commodityId, $isSanction);
                        break;
                    case 'Inland':
                        $aReturn = $model->getInlandcountry();
                        break;
                }

                \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
                return [
                    'country' => $aReturn,
                ];
            }
        } catch (\Exception $ex) {
        }
    }

    /**
     * List of surveryor agent
     * @param id $countryId
     * @return json
     */
    public function actionSurveyoragent()
    {
        try {
            if (Yii::$app->request->isAjax) {
                $aRequest = Yii::$app->request->post();
                $productId = 2;
                $providerId = $_SESSION['insurance.provider_id'];

                $commodityModel = new \common\models\Commodity();

                $commodityId = $commodityModel->getIdByCommodity($aRequest['commodity']);
                $countryId = $aRequest['countryId'];
                $flag = true;
                $isCheck = $this->isCertificate(Yii::$app->user->identity->company_id, $productId, $commodityId);
                if ($isCheck) {
                    $transitTypeModel = new \common\models\TransitType();
                    $transitTypeId = $transitTypeModel->getIdByTransitType($aRequest['type']);

                    // sale term id
                    $aExpolode = explode('-', $aRequest['sale']);
                    if (strpos(trim($aExpolode[0]), '/')) {
                        $aExpolode1 = explode('/', $aExpolode[0]);
                        $saleTerm = trim($aExpolode1[0]);
                    } else {
                        $saleTerm = trim($aExpolode[0]);
                    }
                    $saleTerm = \common\models\SaleTerms::find()->where(['like', 'term', $saleTerm])->one();
                    // agent value
                    $certificateAgent = \common\models\CertificateAgent::find()->where([
                        'transit_type_id' => $transitTypeId, 'term_sale_id' => $saleTerm->id
                    ])->one();
                    if ($certificateAgent->display_value == 2) {
                        $flag = true;
                    } elseif ($certificateAgent->display_value == 1) {
                        $countryId = 174;
                        $flag = true;
                    } else {
                        $flag = false;
                    }
                }
                $data = array();
                if ($flag) {
                    $query = \common\models\Country::find();
                    $query->select([
                        'mi_settling_agent.id as id', 'mi_settling_agent.city as city',
                        'mi_settling_agent.address as address', 'mi_settling_agent.name as name'
                    ])
                        ->joinWith(['survyoeragent'])
                        ->where([
                            'country_id' => $countryId, 'mi_settling_agent.status' => 1, 'mi_settling_agent.provider_id' => $providerId
                        ]);
                    $command = $query->createCommand();
                    $data = $command->queryAll();
                    if (count($data) == 0) {
                        $query = \common\models\Country::find();
                        $query->select([
                            'mi_settling_agent.id as id', 'mi_settling_agent.city as city',
                            'mi_settling_agent.address as address', 'mi_settling_agent.name as name'
                        ])
                            ->joinWith(['survyoeragent'])
                            ->where(['country_id' => 174])
                            ->andWhere(['mi_settling_agent.provider_id' => $providerId]);
                        $command = $query->createCommand();
                        $data = $command->queryAll();
                    }
                } else {
                    $data = array(0 => [false]);
                }

                \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
                return [
                    'agent' => $data,
                ];
            }
        } catch (\Exception $ex) {
        }
    }

    /**
     * List of packaging
     * @param string $transitMode
     * @return json
     */
    public function actionPackaging()
    {
        try {
            if (Yii::$app->request->isAjax) {
                $aRequest = Yii::$app->request->post();

                $transitTypeModel = new \common\models\TransitType();
                $transitTypeId = $transitTypeModel->getIdByTransitType($aRequest['transitType']);
                $transitModeModel = new \common\models\TransitMode();
                $transitModeId = $transitModeModel->getIdByTransitMode($aRequest['transitMode']);
                $commodityModel = new \common\models\Commodity();
                $commodityId = $commodityModel->getIdByCommodity($aRequest['commodity']);
                $isOdc = isset($aRequest['isOdc']) ? $aRequest['isOdc'] : 0;

                $objPackaging = new \common\models\Packaging();
                $data = \yii\helpers\ArrayHelper::map($objPackaging
                    ->getPackaging($commodityId, $transitTypeId, $transitModeId, $isOdc), 'code', 'name');
                \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
                return [
                    'packaging' => $data,
                    'count' => count($data),
                ];
            }
        } catch (\Exception $ex) {
        }
    }

    /**
     * Get Coverage
     * @param string $transitType
     * @param string $transitMode
     * @param string $commodity
     */
    public function actionCoverage()
    {
        try {
            if (Yii::$app->request->isAjax) {
                $aRequest = Yii::$app->request->post();
                $transitTypeModel = new \common\models\TransitType();
                $transitTypeId = $transitTypeModel->getIdByTransitType($aRequest['transitType']);
                $transitModeModel = new \common\models\TransitMode();
                $transitModeId = $transitModeModel->getIdByTransitMode($aRequest['transitMode']);
                $commodityModel = new \common\models\Commodity();
                $commodityId = $commodityModel->getIdByCommodity($aRequest['commodity']);

                $coverageModel = new \common\models\CoverageType();
                $aCoverageType = $coverageModel->getCoverageTypeByCoverageTypeMatrix($commodityId, $transitTypeId, $transitModeId);

                $coverageModel = new \common\models\CoverageWar();
                $coverageWar = $coverageModel->getCoverageWar($transitTypeId, $transitModeId);



                \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
                return [
                    'coverage' => $aCoverageType['type'] . " " . $coverageWar,
                ];
            }
        } catch (\Exception $ex) {
        }
    }

    /**
     * Get Currency list
     * @return json
     */
    public function actionCurrency()
    {
        try {
            if (Yii::$app->request->isAjax) {
                $aRequest = Yii::$app->request->post();
                $query = \common\models\Currency::find();
                $query->select(['code', 'id']);
                if ($aRequest['type'] == 'Inland') {
                    $query->where(['code' => 'INR']);
                }
                $command = $query->createCommand();
                $data = $command->queryAll();
                \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
                return [
                    'currency' => $data,
                ];
            }
        } catch (\Exception $ex) {
        }
    }

    /**
     * To get bov by transit type and commodity
     * @param string $transitType
     * @param string $commodity
     * @return json
     */
    public function actionBov()
    {
        try {
            if (Yii::$app->request->isAjax) {
                $aRequest = Yii::$app->request->post();
                $transitTypeModel = new \common\models\TransitType();
                $transitTypeId = $transitTypeModel->getIdByTransitType($aRequest['transitType']);

                $commodityModel = new \common\models\Commodity();
                $commodityId = $commodityModel->getIdByCommodity($aRequest['commodity']);

                $bovModel = new \common\models\Bov();
                $data = $bovModel->getBov($transitTypeId, $commodityId);

                \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
                return [
                    'bov' => $data,
                ];
            }
        } catch (\Exception $ex) {
        }
    }

    /**
     * To get Sale Terms by bov and transit type
     * @param int $bovId
     * @param string $transitType
     * @return json
     */
    public function actionSaleterms()
    {
        try {
            if (Yii::$app->request->isAjax) {
                $data = Yii::$app->request->post();
                $transitTypeModel = new \common\models\TransitType();
                $transitTypeId = $transitTypeModel->getIdByTransitType($data['transitType']);

                $saleTrmsModel = new \common\models\SaleTerms();
                $data = $saleTrmsModel->getSaleTermsByTransitType($transitTypeId);
                \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
                return [
                    'saleterms' => $data,
                ];
            }
        } catch (\Exception $ex) {
        }
    }

    /**
     * To Get clause
     * @param string $transitType
     * @param string $transitMode
     * @param string $commodity
     * @return json
     */
    public function actionClause()
    {
        //        try {
        if (Yii::$app->request->isAjax) {
            $aRequest = Yii::$app->request->post();
            $insurnceProductType = CargoForm::NON_CONTAINER_PRODUCT_ID;
            $transitType = isset($aRequest['transitType']) ? $aRequest['transitType'] : 'Inland';
            $transitMode = isset($aRequest['transitMode']) ? $aRequest['transitMode'] : '';
            $commodity = isset($aRequest['commodity']) ? $aRequest['commodity'] : '';
            $countryId = isset($aRequest['country']) ? $aRequest['country'] : '';
            $coverage = isset($aRequest['coverage']) ? $aRequest['coverage'] : '';
            $odc = isset($aRequest['odc']) ? $aRequest['odc'] : '';
            $odc_barge = isset($aRequest['odc_barge']) ? $aRequest['odc_barge'] : '';
            $packaging = isset($aRequest['packaging']) ? $aRequest['packaging'] : '';
            $is3rdCountry = isset($aRequest['is_3rd_country']) ? $aRequest['is_3rd_country'] : false;
            $country_type = (isset($aRequest['country_type'])) ? $aRequest['country_type']  : 'P';
            $transitTypeModel = new \common\models\TransitType();
            $transitTypeId = $transitTypeModel->getIdByTransitType($transitType);
            $transitModeModel = new \common\models\TransitMode();
            $transitModeId = $transitModeModel->getIdByTransitMode($transitMode);
            $commodityModel = new \common\models\Commodity();
            $commodityId = $commodityModel->getIdByCommodity($commodity);
            $packagingId = '';
            if ($packaging) {
                $objPackaging = \common\models\Packaging::find()->where(['code' => $packaging])->one();
                $packagingId = $objPackaging->id;
            }
            if ($coverage) {
                $aCoverage = explode('+', $coverage);
                $coverageId = 1;
                if ($aCoverage[0] == 'BASIC_RISK') {
                    $coverageId = 2;
                }
            } else {
                $coverageModel = new \common\models\CoverageType();
                $aCoverageType = $coverageModel->getCoverageTypeByCoverageTypeMatrix($commodityId, $transitTypeId, $transitModeId);

                $coverageId = $aCoverageType['id'];
            }
            $currentDate = date('Y-m-d');
            // get rate matrix with excess clause no.
            $modelRateMatrix = new \common\models\RateMatrix();
            $aRateMatrix = $modelRateMatrix->getRateMatrixByCommodity($commodityId);
            $clauseMatrixModel = new \common\models\ClauseMatrix();
            $w2w  = isset($aRequest['w2wId']) ?  $aRequest['w2wId'] : '';
            if ($currentDate > \Yii::$app->params['clauseDate']) {
                $caluseIds = $clauseMatrixModel->getClauseWithNewMapping(
                    $transitTypeId,
                    $transitModeId,
                    $commodityId,
                    $coverageId,
                    $w2w,
                    $odc,
                    $odc_barge,
                    $packagingId,
                    $_SESSION['insurance.provider_id']
                );
            } else {
                $caluseIds = $clauseMatrixModel->getClause($transitTypeId, $transitModeId, $commodityId, $coverageId);
                if ($w2w) {
                    $w2wMdl = new \common\models\W2W();
                    $clsId = $w2wMdl->getClauseByw2wId($w2w);
                    $caluseIds = $caluseIds . "," . $clsId;
                }
            }
            if ($odc) {
                $caluseIds = str_replace([',84', ', 84'], '', $caluseIds);
            }
            if (
                $country_type == 'H' && isset($_SESSION['company.war_exclustion'])
                && $_SESSION['company.war_exclustion'] == 1
            ) {
                $caluseIds = str_replace([', 23', ', 24'], '', $caluseIds);
            }
            echo $this->createClauseHtml($aRateMatrix, $caluseIds, $countryId, $transitType, $is3rdCountry);
        }
        //        } catch (\Exception $ex) {
        //            
        //        }
    }

    /**
     * To calculate premium
     * @param string $productType
     * @param string $commodity
     * @param int $sumInsured
     * @param string $transitMode
     * @param string $transitType
     * @return type
     */
    public function actionPremium()
    {
        //        try {
        if (Yii::$app->request->isAjax) {
            $data = Yii::$app->request->post();
            $insurnceProductType = CargoForm::NON_CONTAINER_PRODUCT_ID;
            $transitType = isset($data['transitType']) ? $data['transitType'] : '';
            $movement = isset($data['movement']) ? $data['movement'] : 'S';
            $gstin_sez = isset($data['gstin_sez']) ? $data['gstin_sez'] : '0';
            $coverageStr = isset($data['coverage']) ? $data['coverage'] : '';
            $billingType = isset($data['billing_type']) ? $data['billing_type'] : '';
            $sez = isset($data['sez']) ? $data['sez'] : '';
            $isOdc = isset($data['isOdc']) ? $data['isOdc'] : 0;
            $countryType = isset($data['country_type']) ? $data['country_type'] : '';

            if (strtolower($data['productType']) == 'container') {
                $transitType = 'Inland';
                $insurnceProductType = CargoForm::CONTAINER_PRODUCT_ID;
                $w2w = 0;
                $cargoObj = new ContainerForm();
                $flag = true;
                if ($data['sumInsured'] > \Yii::$app->params['maxInsurancePremium']) {
                    $flag = $cargoObj->getUserFlag(Yii::$app->user->identity->company_id);
                }
            } else {
                $w2w = isset($data['w2w']) ? $data['w2w'] : '';
                $cargoObj = new CargoForm();
                $flag = true;
                if ($data['sumInsured'] > \Yii::$app->params['maxInsurancePremium']) {
                    $flag = $cargoObj->getUserFlag(Yii::$app->user->identity->company_id);
                }
                $aCoverage = explode('+', $coverageStr);
                $gstin = isset($data['gstin']) ? $data['gstin'] : '';
                //                    $objUsr = $this->getUserDetailByCompanyId(Yii::$app->user->identity->company_id);
                $aPremium = $this->premiumCalculation(
                    $transitType,
                    $data['transitMode'],
                    $insurnceProductType,
                    $data['commodity'],
                    $data['sumInsured'],
                    $movement,
                    $w2w,
                    $gstin,
                    $data['billing_state'],
                    $aCoverage[0],
                    $billingType,
                    $sez,
                    $isOdc,
                    $countryType,
                    $gstin_sez
                );
                if ($flag) {
                    $aPremium['status'] = 'success';
                    $aPremium['msg'] = '';
                } else {
                    $aPremium['status'] = 'error';
                    $amount = \Yii::$app->params['maxInsurancePremiumShrtMsg'];
                    $aPremium['msg'] =  "Sum insured should not be greater than Rs. $amount Crores.  Please contact DgNote Team at contact@dgnote.com or +91-22-22652123 to buy offline policy.";
                }

                \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
                return [
                    'premium' => $aPremium,
                ];
            }
            $aCoverage = explode('+', $coverageStr);
            $gstin = isset($data['gstin']) ? $data['gstin'] : '';
            //                    $objUsr = $this->getUserDetailByCompanyId(Yii::$app->user->identity->company_id);
            $aPremium = $this->premiumCalculation(
                $transitType,
                $data['transitMode'],
                $insurnceProductType,
                $data['commodity'],
                $data['sumInsured'],
                $movement,
                $w2w,
                $gstin,
                $data['billing_state'],
                $aCoverage[0],
                $billingType,
                $sez,
                $isOdc,
                $countryType
            );
            if ($flag) {
                $aPremium['status'] = 'success';
                $aPremium['msg'] = '';
            } else {
                $aPremium['status'] = 'error';
                $amount = \Yii::$app->params['maxInsurancePremiumShrtMsg'];
                $aPremium['msg'] =  "Sum insured should not be greater than Rs. $amount Crores.  Please contact DgNote Team at contact@dgnote.com or +91-22-22652123 to buy offline policy.";
            } 
            \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
            return [
                'premium' => $aPremium,
            ];
        }
        //        } catch (\Exception $ex) {
        //            
        //        }
    }

    /**
     * 
     * @param string $transitType
     * @param string $transitMode
     * @param int $productId
     * @param string $commodity
     * @param int $sumInsured
     * @return array
     */
    private function premiumCalculation(
        $transitType,
        $transitMode,
        $productId,
        $commodity,
        $sumInsured,
        $movement,
        $w2wCheck,
        $gstin,
        $stateCode,
        $coverage = '',
        $billingType,
        $sez = 0,
        $isOdc = 0,
        $countryType = '',
        $gstin_sez = 0
    ) {
        $aPremium = array();
        $promotion = 0;
        $transitTypeModel = new \common\models\TransitType();
        $transitTypeId = $transitTypeModel->getIdByTransitType($transitType);
        $transitModeModel = new \common\models\TransitMode();
        $transitModeId = $transitModeModel->getIdByTransitMode($transitMode);
        $commodityModel = new \common\models\Commodity();
        $commodityId = $commodityModel->getIdByCommodity($commodity);

        $muliplyVal = 1;
        if ($movement == 'D') {
            $muliplyVal = 2;
        }

        $productModel = new \common\models\InsuranceProduct();

        $aPorductCode = $productModel->getProductCodeByMatrix($productId, $transitTypeId, $transitModeId);
        $isCheck = $this->isCertificate(Yii::$app->user->identity->company_id, $productId, $commodityId);

        $premium = 0;
        if ($isCheck) {
            $objBajajGroup = \common\models\CompanyPolicyCargoCertificate::checkBajajGroupExitOrNotWithInfo(Yii::$app->user->identity->company_id);
            $bajajGroup = isset($objBajajGroup->masterPolicy->bajaj_group) ? $objBajajGroup->masterPolicy->bajaj_group : '';
            $dgnoteRate = Yii::$app->commonutils
                ->getAllRates(
                    $productId,
                    $commodity,
                    $coverage,
                    $transitType,
                    $transitMode,
                    $w2wCheck,
                    $isOdc,
                    false,
                    1,
                    '',
                    $bajajGroup,
                    $countryType,
                    0,
                    \Yii::$app->session->get('company.war_exclustion')
                );
            // print_r($dgnoteRate); die;
            if ($dgnoteRate == false) {
                $premium = 0;
            } else {
                $premium = round($sumInsured * $dgnoteRate / 100);

                if ($productId == 1) {
                    $premium = round($sumInsured * $dgnoteRate / 100, 2);
                }

                if ($isCheck->user_premium > $premium) {
                    $premium = $isCheck->user_premium * $muliplyVal;
                }
            }
        } else {
            $dgnoteRate = $this->getDgnoteRate($commodityId, $w2wCheck, $isOdc, $coverage);
            $netPreumium = round($sumInsured * $dgnoteRate / 100);
            if ($aPorductCode['value'] > $netPreumium) {
                $premium = $aPorductCode['value'] * $muliplyVal;
            } else {
                $premium = $netPreumium * $muliplyVal - $promotion;
            }
        }
        $aPremium['premium'] = $premium;
        $objGst = \yii::$app->gst->getGstProductWise(Yii::$app->params['insuranceproductName']);
        $serviceTaxAmount = $this->getServiceTax($premium, $stateCode, $gstin_sez);
        if ($objGst->ncc_cess_rate > 0) {
            $serviceTaxAmount = $serviceTaxAmount + ($objGst->ncc_cess_rate * $aPremium['premium'] / 100);
        }
        //        $flagSez = $this->checkSEZ();
        if ($sez) {
            $serviceTaxAmount = 0;
        }
        $aPremium['service_tax_amount'] = number_format($serviceTaxAmount, 2, '.', '');
        $aPremium['stamp_duty_amount'] = number_format($this->getStampDuty(
            $transitType,
            $sumInsured,
            $dgnoteRate,
            $premium,
            $aPorductCode,
            $muliplyVal,
            $isCheck
        ), 2, '.', '');
        $aPremium['total_premium'] = number_format(round($premium + $serviceTaxAmount +
            $aPremium['stamp_duty_amount']), 2, '.', '');
        return $aPremium;
    }

    /**
     * Get Dgnote Rate by Commodity
     * @param int $commodityId
     * @return float
     */
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

    /**
     * To get Stamp Duty
     * @param int $transitType
     * @param float $sumInsured
     * @param float $marineRate
     * @param float $netPremmim
     * @param int $productCode
     * @return float
     */
    private function getStampDuty(
        $transitType,
        $sumInsured,
        $marineRate,
        $netPremmim,
        $productCode,
        $muliplyVal,
        $isCertificate
    ) {
        if ($isCertificate) {
            return $stampDuty = 0;
        }
        $stampDuty = 1;
        if ($productCode['code'] == 1001 || $productCode['code'] == 1011) {
            $compareValue = round(($netPremmim / $sumInsured) * 100, 3);
            if ($compareValue <= CargoForm::MINIMUM_STAMP_DUTY) {
                $stampDuty = 1;
            } else {
                $stampDuty = ceil($sumInsured * CargoForm::CURRENT_STAMP_DUTY_RATE / 1500);
            }
        }
        return $stampDuty;
    }

    /**
     * To calculate service tex
     * @param type $premium
     * @return service tax
     */
    private function getServiceTax($premium, $gstin, $gstinSez = 0)
    {
        $objGst = \yii::$app->gst->getGstProductWise(Yii::$app->params['insuranceproductName']);
        $strPinCd = substr($gstin, 0, 2);
        $type = 1;
        if (in_array($strPinCd, \yii::$app->params['MUMBAI_STATE_CODE_GST'])) {
            $type = 0;
        }
        //        $type = strcmp($strPinCd, \yii::$app->params['MUMBAI_PIN_CODE_GST']);
        if ($type == 0 && $gstinSez == 0) {
            $sgst = ($premium * $objGst->sgst_rate / 100);
            $cgst = ($premium * $objGst->cgst_rate / 100);
            $service_tax = $sgst + $cgst;
        } else {
            $igst = ($premium * $objGst->igst_rate / 100);
            $service_tax = $igst;
        }
        return $service_tax;
    }

    /**
     * Create clause html
     * @param int $aRateMatrix
     * @param int $caluseIds
     * @return html
     */
    private function createClauseHtml(
        $aRateMatrix,
        $caluseIds,
        $countryId = '',
        $type = '',
        $is3rdCountry
    ) {
        $is3rdCountry = true;
        $flag = false;
        if ($countryId) {
            $cntryMdl = \common\models\Country::findOne($countryId);
            if ($cntryMdl && $cntryMdl->country_category == 'S') {
                $flag = true;
            }
        }
        if ($type == 'Import') {
            $newClauseIds = '2,' . $caluseIds . "," . $aRateMatrix['excess_clause_number'];
        } elseif ($type == 'Export') {
            $newClauseIds = '3,' . $caluseIds . "," . $aRateMatrix['excess_clause_number'];
        } else {
            $newClauseIds = $caluseIds . "," . $aRateMatrix['excess_clause_number'];
        }

        $clauseModel = new \common\models\Clause();
        $aClause = $clauseModel->getClauseDetails($newClauseIds);
        $a3rdCountry = [];
        if ($is3rdCountry) {
            $count = count($a3rdCountry);
            $first = $count + 1;
            $a3rdCountry[0]['clause_description'] = 'This policy is subject to the insurable interest arrived by the Terms of Sale, as mentioned in the Purchase Invoice Movement details and the Sales Invoice Movement details, taken together. The coverage in no respect fall out of the scope of the said Terms of Sale.';
            $a3rdCountry[0]['type'] = 'Cover Clause';
            $a3rdCountry[0]['status'] = 0;
            $a3rdCountry[1]['clause_description'] = 'Excluding Cargo inland transit from or to African/CIS countries (beyond Ports of these countries), i.e. coverage in no case beyond the source warehouse to destination port in case of Exports & from load port to destination warehouse in case of Imports.';
            $a3rdCountry[1]['type'] = 'Standard Conditions and Warranties';
            $a3rdCountry[1]['status'] = 0;
            $a3rdCountry[2]['clause_description'] = 'Excluding Cargo inland transit from or to Nepal, Bangladesh, Bhutan and Pakistan (beyond Indian Border), i.e. coverage in no case beyond the source warehouse to Indian border with the respective country in case of Exports & from Indian border with the respective country to destination warehouse in case of Imports.';
            $a3rdCountry[2]['type'] = 'Standard Conditions and Warranties';
            $a3rdCountry[2]['status'] = 0;
            $aClause = array_merge($aClause, $a3rdCountry);
        }
        return $this->renderPartial('_clause.php', [
            'aClause' => $aClause,
            'aRateMatrix' => $aRateMatrix, 'flag' => $flag
        ]);
    }

    public function actionSurvyoerdetail($agentId)
    {
        try {

            $query = new \common\models\SettlingAgent();
            $aRresponse = $query->getServoreDetailById($agentId);
            \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
            return [
                'agent_info' => $aRresponse,
            ];
        } catch (\Exception $ex) {
        }
    }

    /**
     * Get Coverage
     * @param string $transitType
     * @param string $transitMode
     * @param string $commodity
     */
    public function actionCargocoverage()
    {
        try {
            if (Yii::$app->request->isAjax) {
                $aRequest = Yii::$app->request->post();
                date_default_timezone_set('UTC');
                $isOpen = (isset($aRequest['isOpen'])) ? $aRequest['isOpen']  : false;
                $isOdc = (isset($aRequest['odc'])) ? $aRequest['odc']  : 0;
                $country_type = (isset($aRequest['country_type'])) ? $aRequest['country_type']  : 'P';
                //                $checkFromCurrentDt = strtotime(date('Y-m-d',strtotime('-3 days')));
                $checkFromCurrentDt = strtotime(date('Y-m-d'));
                $backDate = !empty($aRequest['transit']) ? strtotime($aRequest['transit']) : strtotime(date('Y-m-d'));

                $transitTypeModel = new \common\models\TransitType();
                $transitTypeId = $transitTypeModel->getIdByTransitType($aRequest['transitType']);
                $transitModeModel = new \common\models\TransitMode();
                $transitModeId = $transitModeModel->getIdByTransitMode($aRequest['transitMode']);

                $commodityModel = new \common\models\Commodity();
                $commodityId = $commodityModel->getIdByCommodity($aRequest['commodity']);

                $productId = CargoForm::NON_CONTAINER_PRODUCT_ID;

                $productModel = new \common\models\InsuranceProduct();
                $aInsuranceProduct = $productModel->getProductCodeByMatrix($productId, $transitTypeId, $transitModeId);

                $isCerticate = $this->isCertificate(\yii::$app->user->identity->company_id, $productId, $commodityId);

                if (
                    $country_type == 'H' && isset($_SESSION['company.war_exclustion'])
                    && $_SESSION['company.war_exclustion'] == 1
                ) {
                    $coverageWar = '';
                } else {
                    $coverageModel = new \common\models\CoverageWar();
                    $coverageWar = $coverageModel->getCoverageWar($transitTypeId, $transitModeId);
                }


                $saleTermId = (!empty($aRequest['termSale'])) ? $aRequest['termSale']  : null;
                if ($aRequest['transitType'] == 'Inland') {
                    $saleTermId = null;
                }

                if ($aRequest['backdate'] == 1) {
                    $coverageType = 'BASIC_RISK';
                    $coverage[] = 'BASIC_RISK';
                } else {
                    $cvrgTypMdl = \common\models\Commodity::find()->where(['code' => $aRequest['commodity']])->one();

                    if ($cvrgTypMdl->coverage_type != '') {
                        $coverage[] = $cvrgTypMdl->coverage_type;
                    } else {
                        $coverModel = new \common\models\CoverageType();
                        $coverage = [];
                        $flag = 0;
                        if ($isCerticate) {
                            $flag = 1;
                        }
                        $coverageType = $coverModel->getCoverageTypeBySaleTermWithNewData(
                            $saleTermId,
                            $transitTypeId,
                            $transitModeId,
                            $flag
                        );
                        if (count($coverageType) > 0) {
                            foreach ($coverageType as $obj) {
                                $coverage[] = $obj->type;
                            }
                        }
                    }
                }
                if ($aRequest['commodity'] == 'COM27' && $isOdc == 1) {
                    $coverage = [];
                    $coverage[] = 'BASIC_RISK';
                }

                if ($backDate < $checkFromCurrentDt && $isOpen == false) {
                    $coverage = [];
                    $coverage[] = 'BASIC_RISK';
                }
                $aResult = [];
                \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
                $aResult['coverage'] = $coverage;
                $aResult['coverage_war'] = $coverageWar;
                $aResult['status'] = 'success';
                return  $aResult;
            }
        } catch (\Exception $ex) {
        }
    }

    public function actionContinersize($commodity)
    {
        try {
            $commodityModel = new \common\models\Commodity();
            $id = $commodityModel->getIdByCommodity($commodity);
            $conSizeModel = new \common\models\ContainerSize();
            $aResponse = $conSizeModel->getContainerSizeByCommodityId($id);
            \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
            return [
                'con_size' => $aResponse,
            ];
        } catch (\Exception $ex) {
        }
    }

    /**
     * 
     */
    public function actionTransittype()
    {
        try {
            $query = \common\models\TransitType::find();
            $query->select(['id', 'name']);
            $aResponse = $query->createCommand()->queryAll();
            \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
            return [
                'transit' => $aResponse,
            ];
        } catch (\Exception $ex) {
        }
    }
    public function actionIssuepolicy()
    {
        Yii::$app->Issuepolicy->issuePolicy();
        //        $newComponent = new \frontend\insurance\components\MasterDataValidatonComponent();
        //        echo $newComponent->checkCommodity('COM10','container');
        die;
    }

    public function actionConfirmation()
    {
        ini_set('error_reporting', E_STRICT);
        try {
            $userId = \yii::$app->user->id;
            $cargoModel1 = \common\models\Quotation::findOne(Yii::$app->session->get('user.quote_id'));
            if ($cargoModel1->offline_status != 'approved') {
                $cargoModel1->is_draft = 1;
                $cargoModel1->save([false, 'is_draft', 'cr_2_offline', 'is_offline']);
            }

            if (!empty($cargoModel1->survey_report) && $cargoModel1->offline_status != 'approved') {
                $cargoModel1->is_offline = 1;
                $cargoModel1->is_survey = 1;
                $cargoModel1->save([false, 'is_offline', 'is_survey']);
            }

            $aStatus = Yii::$app->commonutils->checkMasterPolicyAndCoverageDate($cargoModel1);
            $cargotype = strtolower($cargoModel1->transit_type);
            $id = $cargoModel1->id;
            if ($aStatus['status'] == 'failed') {
                Yii::$app->session->setFlash('error', $aStatus['error']);
                $rUrl = "edit-draft?type=$cargotype&id=$id&isBuy=false";
                return $this->redirect($rUrl);
            }

            $above5Cr = false;
            if (
                $cargoModel1->sum_insured >= \Yii::$app->params['maxInsurancePremium']
                && ($cargoModel1->is_offline == 0 || $cargoModel1->cr_2_offline == 0)
            ) {
                $cargoModel1->is_offline = 1;
                $cargoModel1->cr_2_offline = 1;
                $above5Cr = true;
                $cargoModel1->save([false, 'is_draft', 'cr_2_offline', 'is_offline']);
                Yii::$app->session->setFlash('error', 'Your ceritificate is 5Cr. above. Please upload the file for approval.');
                $cargotype = strtolower($cargoModel1->transit_type);
                $id = $cargoModel1->id;
                $rUrl = "edit-draft?type=$cargotype&id=$id&isBuy=false";
                return $this->redirect($rUrl);
            }

            if (Yii::$app->request->post()) {
                $cargoModel2 = \common\models\Quotation::findOne(Yii::$app->session->get('user.quote_id'));
                $cargoModel2->is_draft = 0;
                $cargoModel2->request_time = date('Y-m-d H:i:s');
                $cargoModel2->save([false, 'is_draft', 'request_time']);
                $aForm = Yii::$app->request->post('CargoForm');
                $aRequest = Yii::$app->request->post();
                $cargoModel = \common\models\Quotation::findOne(Yii::$app->session->get('user.quote_id'));
                if ($cargoModel->is_odc == 1) {
                    $cargoModel->status = 1;
                    if ($cargoModel->save()) {
                        Yii::$app->session->setFlash('success', 'Your certificate request has been successfully sent for approval!');
                        // send mail if upload format exist...
                        if (!empty($cargoModel->upload_invoice)) {
                            // insert for mail queue....
                            $mailQueueObj = new \common\models\EmailQueue();
                            $mailQueueObj->insertQueueForMail(
                                $cargoModel->id,
                                'insurance_is_odc_for_user',
                                1
                            );
                        } else {
                            \Yii::$app->commonMailSms->sendMailAtException(
                                '',
                                'Insurance ODC Mail Issue',
                                "Issue in inserting mail in queue for upload "
                                    . "invoice of ODC Insurance for booking id $cargoModel->id."
                            );
                        }
                        return Yii::$app->getResponse()->redirect('/insurance/user/policy');
                    } else {
                        $aError = $cargoModel->getErrors();
                        $error = '';
                        if (isset($aError)) {
                            $error = $aError[0];
                        }
                        $quoteId = $cargoModel->id;
                        \Yii::$app->commonMailSms->sendMailAtException(
                            $cargoModel->id,
                            "ODC mail has not sent for quote id $quoteId",
                            $error
                        );
                    }
                } elseif ($aForm['offline_status'] != 'approved') {
                    $cargoModel->status = 1;
                    if ($cargoModel->save(false, ['status'])) {
                        Yii::$app->session->setFlash('success', 'Your certificate request has been successfully sent for approval!');
                        // send mail if upload format exist...
                        if (!empty($cargoModel->upload_invoice)) {
                            // insert for mail queue....
                            $mailQueueObj = new \common\models\EmailQueue();
                            $mailQueueObj->insertQueueForMail(
                                $cargoModel->id,
                                'insurance_offline_format_for_user',
                                1
                            );
                        } else {
                            if ($cargoModel->back_date == 0) {
                                \Yii::$app->commonMailSms->sendMailAtException('', "Insurance Offline Mail Issue", "Issue in inserting mail in queue for upload invoice "
                                    . "of Offline Insurance for booking id $cargoModel->id.");
                            } else {
                                // insert for mail queue....
                                $mailQueueObj = new \common\models\EmailQueue();
                                $mailQueueObj->insertQueueForMail(
                                    $cargoModel->id,
                                    'insurance_offline_format_for_user',
                                    1
                                );
                            }
                        }
                        return Yii::$app->getResponse()->redirect('/insurance/user/policy');
                    } else {
                        $aError = $cargoModel->getErrors();
                        $error = '';
                        if (isset($aError)) {
                            $error = $aError[0];
                        }
                        $quoteId = $cargoModel->id;
                        \Yii::$app->commonMailSms->sendMailAtException(
                            $cargoModel->id,
                            "Offline mail has not sent for quote id $quoteId",
                            $error
                        );
                    }
                } else {
                    Yii::$app->session->set('user.quote_id', $aForm['id']);
                }
            }
            $query = new \common\models\Quotation();
            $data = $query->getQuote(Yii::$app->session->get('user.quote_id'), $userId);
            $mdComRt = Yii::$app->issueCertificate->isCompanyRateExist(\yii::$app->user->identity->company_id, $data['insurance_product_type_id']);
            $Quoteid = "";

            if (!Yii::$app->session->has('user.quote_id')) {
                return Yii::$app->getResponse()->redirect('/user/dashboard');
            }

            //      $quoteId = 28;
            $aContainer = array();

            if (count($data) > 0 && $data['productType'] == 'Container') {
                $aContainer = $query->getContainerInfo(Yii::$app->session->get('user.quote_id'));
            }

            $blanceModel = new \common\models\UserAccountBalance();
            $balance = $blanceModel->getUserAccountBalance($userId);
            $objCredit = \common\models\TransportCredit::findOne([
                'company_id'
                => \yii::$app->user->identity->company_id, 'product_id' => 1, 'status' => 'Active'
            ]);
            $credit = false;
            if ($objCredit && $this->checkCompanyCredit($balance, $data['total_premium'])) {
                $credit = true;
            }
            return $this->render('confirmation', [
                'data' => $data,
                'userbalance' => $balance,
                'checkCmpRt' => $mdComRt,
                'aContainer' => $aContainer,
                'balance' => $balance,
                'product' => $data['productType'],
                'cargotype' => $data['transit_type'],
                'objCredit' => $objCredit,
                'credit' => $credit,
                'above5Cr' => $above5Cr
            ]);
        } catch (\Exception $ex) {
            \Yii::$app->commonMailSms->sendMailAtException('', "Issue in Insurance confirmation mail.", $ex->getMessage());
        }
    }

    private function checkPremium(
        $transitType,
        $transitMode,
        $productId,
        $commodity,
        $sumInsured,
        $premium,
        $movement = 'S'
    ) {
        $flag = false;
        $aPremium = $this->premiumCalculation(
            $transitType,
            $transitMode,
            $productId,
            $commodity,
            $sumInsured,
            $movement
        );


        if (round($aPremium['total_premium']) == round($premim)) {
            $flag  = true;
        }
        return $flag;
    }

    public function actionLocationlabel()
    {
        try {
            if (Yii::$app->request->isAjax) {
                $data = Yii::$app->request->post();
                $transitTypeModel = new \common\models\TransitType();
                $transitTypeId = $transitTypeModel->getIdByTransitType($data['transitType']);
                $locationModel = new \common\models\InsuranceLocationMatrix();
                $aLoction = $locationModel->getCoverageLoction($transitTypeId, $data['saleTerm']);
                \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
                return [
                    'location' => $aLoction,
                ];
            }
        } catch (\Exception $ex) {
        }
    }

    private function removeCommaFromAmount($amount)
    {
        return str_replace(",", "", $amount);
    }

    public function actionCheckw2w()
    {
        try {
            if (Yii::$app->request->isAjax) {
                $aRequest = Yii::$app->request->post();

                $transitTypeModel = new \common\models\TransitType();
                $transitTypeId = $transitTypeModel->getIdByTransitType($aRequest['transitType']);
                $transitModeModel = new \common\models\TransitMode();
                $transitModeId = $transitModeModel->getIdByTransitMode($aRequest['transitMode']);
                $saleTermId = $aRequest['saleTerm'];
                $originCountryId = $aRequest['origin'];
                $destinationCountryId = $aRequest['destination'];

                $coverage = $aRequest['coverage'];
                $coverageId = '';
                if ($coverage != '') {
                    $aCoverage = explode("+", $coverage);
                    $coverageId = \common\models\CoverageType::find()->select(['id'])->where(['type' => $aCoverage[0]]);
                }

                if ($originCountryId != "India") {
                    $originCountryId = $this->getCountryType($originCountryId);
                }

                if ($destinationCountryId != "India") {
                    $destinationCountryId = $this->getCountryType($destinationCountryId);
                }
                $productModel = new \common\models\InsuranceProduct();
                $aInsuranceProduct = $productModel->getProductCodeByMatrix(
                    CargoForm::NON_CONTAINER_PRODUCT_ID,
                    $transitTypeId,
                    $transitModeId
                );
                $objBajajGroup = \common\models\CompanyPolicyCargoCertificate::checkBajajGroupExitOrNotWithInfo(Yii::$app->user->identity->company_id);
                if (!empty($objBajajGroup->masterPolicy->bajaj_group)) {
                    if ($objBajajGroup->masterPolicy->is_w2w == 1) {
                        $w2wMdl = new \common\models\W2W();
                        $data = $w2wMdl->getW2W(
                            $transitTypeId,
                            $transitModeId,
                            $saleTermId,
                            $destinationCountryId,
                            $originCountryId,
                            $coverageId,
                            $aInsuranceProduct['id']
                        );
                    } else {
                        $data = false;
                    }
                } else {
                    $w2wMdl = new \common\models\W2W();
                    $data = $w2wMdl->getW2W($transitTypeId, $transitModeId, $saleTermId, $destinationCountryId, $originCountryId, $coverageId, $aInsuranceProduct['id']);
                }
                \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

                $locationModel = new \common\models\InsuranceLocationMatrix();
                $aLoction = $locationModel->getCoverageLoction($transitTypeId, $saleTermId);
                if ($data) {
                    $aLoction['label_coverage_to'] = "Buyer's Warehouse";
                }
                return [
                    'w2w' => $data,
                    'label' => $aLoction
                ];
            }
        } catch (\Exception $ex) {
        }
    }

    private function getCountryType($countryId)
    {
        $cntryMd = new \common\models\Country();
        return $cntryMd->getCountryTypeByCountryId($countryId);
    }

    public function actionGetuserdetails()
    {
        try {
            $aRequest = Yii::$app->request->post();
            $cntctMdl = new \common\models\DgnoteUserContacts();
            $data = $cntctMdl->getUserContactDetails(1, Yii::$app->user->identity->id, $aRequest['q']);

            \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
            return [
                'contact' => $data,
            ];
        } catch (\Exception $ex) {
        }
    }

    private function saveUserContactDetails(
        $institueName,
        $address,
        $city,
        $state,
        $pincode,
        $gstin,
        $party_name,
        $billingCity,
        $billingState,
        $billingAddress,
        $billingPincode
    ) {
        $mdl = new \common\models\DgnoteUserContacts();
        $checkDtl = $mdl->checkContactDetailsWithObj(
            1,
            Yii::$app->user->identity->id,
            $institueName,
            $address,
            $party_name,
            $billingAddress
        );
        if (empty($checkDtl)) {
            $mdl->product_id = 1;
            $mdl->user_id = Yii::$app->user->identity->id;
            $mdl->company = $institueName;
            $mdl->city = $city;
            $mdl->state = $state;
            $mdl->address = $address;
            $mdl->pincode = $pincode;
            $mdl->gstin = $gstin;
            $mdl->party_name = $party_name;
            $mdl->billing_address = $billingAddress;
            $mdl->billing_city = $billingCity;
            $mdl->billing_state = $billingState;
            $mdl->billing_pincode = $billingPincode;
            $mdl->created_at = date("Y-m-d H:i:s");
            $mdl->modified_at = date("Y-m-d H:i:s");
            $mdl->save();
        } else {
            $checkDtl->product_id = 1;
            $checkDtl->user_id = Yii::$app->user->identity->id;
            $checkDtl->company = $institueName;
            $checkDtl->city = $city;
            $checkDtl->state = $state;
            $checkDtl->address = $address;
            $checkDtl->pincode = $pincode;
            $checkDtl->gstin = $gstin;
            $checkDtl->party_name = $party_name;
            $checkDtl->billing_address = $billingAddress;
            $checkDtl->billing_city = $billingCity;
            $checkDtl->billing_state = $billingState;
            $checkDtl->billing_pincode = $billingPincode;
            $checkDtl->modified_at = date("Y-m-d H:i:s");
            $checkDtl->save(false);
        }
    }

    public function actionGetuserdetailsbyid()
    {
        try {
            $aRequest = Yii::$app->request->post();
            $cntctMdl = new \common\models\DgnoteUserContacts();
            $data = $cntctMdl->getUserContactDetailsById($aRequest['id'], Yii::$app->user->identity->id);
            \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
            return [
                'details' => $data,
            ];
        } catch (\Exception $ex) {
        }
    }

    public function actionUploadfile()
    {
        try {
            if (isset($_POST['commodity'])) {
                $aFile = explode(".", $_FILES['CsvDoc']['name']);
                if (strtolower($aFile[1]) == "csv") {
                    $commodityModel = new \common\models\Commodity();
                    $commodityId = $commodityModel->getIdByCommodity($_POST['commodity']);

                    $finalArr = $aError = $aNo = $aFnal = array();

                    $file = $_FILES['CsvDoc']['tmp_name'];
                    $filecsv = file($file);
                    $headerArr = array("No", "Size", "Value");
                    foreach ($filecsv as $key => $value) {
                        if ($key > 0) {
                            $dataArr = explode(",", $value);
                            $aNo[] = strtolower($dataArr[0]);

                            if ($err1 = $this->checkData($dataArr)) {
                                $aError[] = $err1;
                                break;
                            }
                            $dataArr = explode(",", str_replace(array("\r", '\n'), "", implode(",", $dataArr)));
                            $Arr = array_combine(array_map('trim', $headerArr), array_map('trim', $dataArr));
                            foreach ($Arr as $key => $value) {
                                $aFnal[$key] =  preg_replace("/[\x80-\xFF]/", '', $value);
                            }
                            $finalArr[] = $aFnal;
                            $err = $this->validateRecords($aFnal, $commodityId, $_POST['commodity']);
                            if ($err) {
                                $aError[] = $err;
                                break;
                            }
                        }
                    }
                    if (count($aNo) != count(array_unique($aNo))) {
                        $aError[] = "Container No. must be unique.";
                    }
                } else {
                    $aError[] = "Please upload only csv file.";
                }
            } else {
                $aError[] = "Please select commodity.";
            }
            \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

            if (!empty($aError)) {
                return [
                    'error' => $aError,
                ];
            } else {
                if (count($finalArr) > 0) {
                    return [
                        'details' => $finalArr,
                    ];
                } else {
                    $aError[] = "Uploaded File can not be empty!";
                    return [
                        'error' => $aError,
                    ];
                }
            }
        } catch (\Exception $ex) {
        }
    }

    /**
     * Validate upload csv data
     * @param array $param
     * @param int $commodityId
     * @param string $comodity
     * @return string
     */
    private function validateRecords($param, $commodityId, $comodity)
    {
        $err = array();
        $frm = new ContainerForm();

        if (empty($param['No']) || empty($param['Size']) || empty($param['Value'])) {
            return $err = "Fields can not be blank.";
        } else if (!preg_match("/^[0-9]+$/", $param['Value'])) {
            return $err = "Please check container value for " . $param['No'] . ".";
        } else {

            $checkSize = $frm->checkContainerSizeWithCommodity($commodityId, $param['Size']);
            if ($checkSize != 1) {
                return $err = "Please check container size for " . $param['No'] . ".";
            }
            //            else {
            //                $count = $this->checkValidContainerNo(Yii::$app->user->identity->id, trim($param['No']),
            //                        Yii::$app->params['insurance_duplicasy_check_dayks']);
            //                if ($count > 0) {
            //                    return $err = "Container no '".$param['No']."' is already exist.";
            //                }
            //            }
        }
    }

    private function checkValidContainerNo($userId, $containerNo, $validDays)
    {
        $frm = new ContainerForm();
        return $count = $frm->checkUniqueContainerNo($userId, $containerNo, $validDays);
        //        if ($count > 0) {
        //            return $err = "Container no '".$param['No']."' is already exist.";
        //        }
    }

    public function checkData($aValues)
    {
        if (empty($aValues[0]) || empty($aValues[1]) || empty($aValues[2])) {
            return $err = "Fields can not be blank.";
        } else if (!preg_match("/^[0-9]+$/", trim($aValues[2]))) {
            return $err = "Please remove special character from container no. $aValues[0].";
        }
    }

    public function actionDownload($type='')
    {
        if($type=='inland'){
            $doc = 'inlandSample.xlsx';
        } else {
            $doc = Yii::$app->params['containerFile'];
        }
        $doc = Yii::$app->params['containerFile'];
        $path = Yii::$app->params['contanerfilepath'] . "container/";
        if (file_exists($path . "/" . $doc)) {
            Yii::$app->response->sendFile($path . "/" . $doc);
        } else {
            Yii::$app->session->setFlash('error', 'File does not exist!');
            return $this->redirect('container');
        }
    }

    public function actionDownloadinland()
    {
        $doc = 'inlandSample.xlsx';
        $path = Yii::$app->params['uploadPath'] . "container/";
        if (file_exists($path . "/" . $doc)) {
            Yii::$app->response->sendFile($path . "/" . $doc);
        } else {
            Yii::$app->session->setFlash('error', 'File does not exist!');
            return $this->redirect('transit-upload');
        }
    }

    public function actionImport()
    {
        $accountbalance = new \common\models\UserAccountBalance();
        $balance = $accountbalance->getUserAccountBalance(Yii::$app->user->identity->id);
        $cargoModel = new CargoForm();

        try {
            Yii::$app->session->removeFlash('success');
            if ($cargoModel->load(Yii::$app->request->post())) {
                $aRequest = Yii::$app->request->post();
                unset($aRequest['CargoForm']['comma_sum_insured']);
                $this->saveUserContactDetails(
                    $aRequest['CargoForm']['institution_name'],
                    $aRequest['CargoForm']['address'],
                    $aRequest['CargoForm']['city'],
                    $aRequest['CargoForm']['state'],
                    $aRequest['CargoForm']['pincode'],
                    $aRequest['CargoForm']['billing_city'],
                    $aRequest['CargoForm']['billing_state'],
                    $aRequest['CargoForm']['billing_address'],
                    $aRequest['CargoForm']['billing_pincode']
                );
                $productModel = new \common\models\InsuranceProduct();
                $transitTypeModel = new \common\models\TransitType();
                $transitTypeId = $transitTypeModel->getIdByTransitType($cargoModel->transit_type);
                $transitModeModel = new \common\models\TransitMode();
                $transitModeId = $transitModeModel->getIdByTransitMode($cargoModel->transit_mode);
                $aInsuranceProduct = $productModel->getProductCodeByMatrix(
                    CargoForm::NON_CONTAINER_PRODUCT_ID,
                    $transitTypeId,
                    $transitModeId
                );
                $cargoModel->product_code = $aInsuranceProduct['code'];
                $cargoModel->surveyor_country = $cargoModel->destination_country;
                $cargoModel->surveyor_address = $aRequest['CargoForm']['surveyor_address'];
                $cargoModel->surveyor_agent = $aRequest['CargoForm']['surveyor_agent'];
                $cargoModel->valuation_basis = ($aRequest['CargoForm']['valuation_basis'] == 'Terms Of Sale') ? 'TOS' : $aRequest['CargoForm']['valuation_basis'];
                $cargoModel->contact_name = Yii::$app->user->identity->first_name . " " . Yii::$app->user->identity->last_name;
                $cargoModel->mobile = Yii::$app->user->identity->mobile;
                $cargoModel->country = Yii::$app->user->identity->country;
                $cargoModel->total_premium = $this->removeCommaFromAmount($aRequest['CargoForm']['total_premium']);
                $cargoModel->service_tax_amount = $this->removeCommaFromAmount($aRequest['CargoForm']['service_tax_amount']);
                $cargoModel->premium = $this->removeCommaFromAmount($aRequest['CargoForm']['premium']);

                if ($cargoModel->validate()) {

                    $validationObj = new \frontend\insurance\components\MasterDataValidatonComponent();
                    $aError = $validationObj->checkServerValidation($cargoModel);

                    if ($aError['status']) {
                        if ($quote = $cargoModel->save()) {
                            Yii::$app->session->set('user.quote_id', $quote->id);
                            return $this->redirect("confirmation");
                        } else {
                            Yii::$app->session->setFlash('error', 'There is some error please try again.');
                            return $this->redirect('cargo');
                        }
                    } else {
                        Yii::$app->session->setFlash('error', $aError['error']);
                        return $this->redirect('cargo');
                    }
                }
            }
        } catch (\Exception $e) {
            //            echo "<pre>";   print_r($e);    die;
        }
        return $this->render('cargo', [
            'model' => $cargoModel,
            'balance' => $balance,
        ]);
    }

    public function actionSearch()
    {
        Yii::$app->assetManager->bundles['yii\web\JqueryAsset'] = false;
        Yii::$app->assetManager->bundles['yii\web\YiiAsset'] = false;
        $model = new \frontend\insurance\models\Search();
        $aResponse = array();
        $model = new \frontend\insurance\models\Search();
        $sResult = '';
        $aResponse = $model->getAllValues();
        if (count($aResponse) > 0) {
            $sResult = '<table class="table table-striped table-bordered MT10">';
            $sResult = $sResult . '<thead><th style="width:10%;text-align:center;">Chapter</th>'
                . '<th style="width:45%;text-align:center;">Product Name</th>'
                . '<th style="width:45%;text-align:center;">Commodity</th></thead>';

            $sResult = $sResult . '<thead>';
            $rows = '';
            foreach ($aResponse as $key => $aValue) {
                $desc = !empty($aValue['description']) ? trim($aValue['description']) : '';
                if (!empty($aValue['commodity_id'])) {
                    $code = "'" . $aValue['code'] . "'";
                    $rows =  $rows . '<tr>';
                    $rows = $rows . '<td style="text-align:center;">' . $aValue['id'] . '</td>'
                        . '<td style="text-align:center;">' . trim($aValue['title']) . '</td>'
                        . '<td><a href="javascript:void(0);"  id="search-com-result" onclick="checkComResult(' . $code . ')">' . $desc . '</a></td>' . '</div>';
                    $rows = $rows . '</tr>';
                } else {
                    $rows = $rows . '<tr><td style="text-align:center;">' . $aValue['id'] . '</td><td style="text-align:center;">' . trim($aValue['title']) . "</td><td style='text-align:center;'>N/A</td></tr>";
                }
            }
            $sResult = $sResult . $rows;
            $sResult = $sResult . '</thead></table>';
            $sResult = $sResult;
        }
        return $this->renderAjax('search', [
            'model' => $model,
            'aResponse' => $sResult
        ]);
    }

    public function actionSearchresult()
    {
        Yii::$app->assetManager->bundles['yii\web\JqueryAsset'] = false;
        Yii::$app->assetManager->bundles['yii\web\YiiAsset'] = false;
        $aRequest = Yii::$app->request->post();
        $aResponse = array();
        $sResult = '';
        $model = new \frontend\insurance\models\Search();
        //        if (empty($aRequest['Search']['search'])) {
        //            $sResult = "error||"."Search cannot be blank.";
        //            echo $sResult;
        //            die;
        //        }
        if ($model->load(Yii::$app->request->post())) {
            $str = str_replace(array("Chapter", 'chapter'), array("", ""), $aRequest['Search']['search']);
            $aResponse = $model->search($str);
            if (count($aResponse) > 0) {
                $sResult = '<table class="table table-striped table-bordered MT10">';
                $sResult = $sResult . '<thead><th>Chapter</th><th>Product Name</th><th>Commodity</th></thead>';

                $sResult = $sResult . '<thead>';
                $rows = '';
                foreach ($aResponse as $key => $aValue) {
                    $desc = !empty($aValue['description']) ? trim($aValue['description']) : '';
                    if (!empty($aValue['commodity_id'])) {
                        $code = "'" . $aValue['code'] . "'";
                        $rows =  $rows . '<tr>';
                        $rows = $rows . '<td>' . $aValue['id'] . '</td><td>' . trim($aValue['title']) . '</td><td><a href="javascript:void(0);"  id="search-com-result" onclick="checkComResult(' . $code . ')">' . $desc . '</a></td>' . '</div>';
                        $rows = $rows . '</tr>';
                    } else {
                        $rows = $rows . '<tr><td>' . $aValue['id'] . '</td><td>' . trim($aValue['title']) . "</td><td>N/A</td></tr>";
                    }
                }
                $sResult = $sResult . $rows;
                $sResult = $sResult . '</thead></table>';
                $sResult = "success||" . $sResult;
            } else {
                $sResult = "error||" . "No Result Found!";
            }
            echo $sResult;
            die;
        }
    }

    public function actionCheckcontainer()
    {
        try {
            if (Yii::$app->request->isAjax) {
                $aRequest = Yii::$app->request->post();
                $aContNo = array_pop($aRequest['InsuranceContainer']['container_no']);
                $aContSize = array_pop($aRequest['InsuranceContainer']['container_size']);
                $aContValue = array_pop($aRequest['InsuranceContainer']['container_value']);

                if (!empty($aRequest['InsuranceContainer']['container_no'])) {
                    $aContainer = $aNonValid = $aDigit = array();
                    $days = Yii::$app->params['insurance_duplicasy_check_dayks'];
                    $str = '<div class="MB10" style="font-weight:bold">Please find below exceptions:</div><div style="word-wrap: break-word;">';

                    $flag = $flag1 = false;
                    $inc = 1;
                    $str1 = '';
                    foreach ($aRequest['InsuranceContainer']['container_no'] as $value) {
                        $a = $b = '';
                        if (!empty($value)) {

                            $count = $this->checkValidContainerNo(
                                Yii::$app->user->identity->id,
                                trim($value),
                                Yii::$app->params['insurance_duplicasy_check_dayks']
                            );
                            if ($count > 0) {
                                $a = "Duplicate";
                                $flag = true;
                            } else {
                                $count = count(array_keys($aRequest['InsuranceContainer']['container_no'], $value, true));
                                if ($count > 1) {
                                    $a = "Duplicate";
                                    $flag = true;
                                }
                            }

                            if (!$this->checkDigit($value)) {
                                $b = "Invalid Container No";
                                $flag = true;
                            }


                            if ($flag) {

                                if ($a != '' && $b != '') {
                                    $str1 = $str1 . "<div class='MB10'>" . $inc . ". " . $value . " " . "(" . $a . ", " . $b . ")" . "</div>";
                                    $inc++;
                                } else if ($a != '') {
                                    $str1 = $str1 . "<div class='MB10'>" . $inc . ". " . $value . " " . "(" . $a . ")" . "</div>";
                                    $inc++;
                                } else if ($b != '') {
                                    $str1 = $str1 . "<div class='MB10'>" . $inc . ". " . $value . " " . "(" . $b . ")" . "</div>";
                                    $inc++;
                                }
                            }
                        } else {
                            echo "error||1";
                            die;
                        }
                    }

                    if ($flag) {
                        $str = $str . $str1 . "</div>";
                        echo $err = "error||$str";
                        die;
                    } else {
                        echo "success||<div class='error'>No duplicate entry!</div>";
                        die;
                    }
                } else {
                    echo "error||<div class='error'>Please fill container information!</div>";
                    die;
                }
            }
        } catch (\Exception $ex) {
        }
    }

    /**
     * To check container is ISO certified or not..
     */
    private function checkDigit($mark)
    {
        $char2num = ['A' => 10, 'B' => 12, 'C' => 13, 'D' => 14, 'E' => 15, 'F' => 16, 'G' => 17, 'H' => 18, 'I' => 19, 'J' => 20, 'K' => 21, 'L' => 23, 'M' => 24, 'N' => 25, 'O' => 26, 'P' => 27, 'Q' => 28, 'R' => 29, 'S' => 30, 'T' => 31, 'U' => 32, 'V' => 34, 'W' => 35, 'X' => 36, 'Y' => 37, 'Z' => 38];
        $acc = 0;
        $num = str_split(strtoupper($mark));

        $flag = true;
        $count = count($num);

        if ($count == 11) {
            for ($i = 0; $i < 10; $i++) {
                if ($i < 4) {
                    if (!array_key_exists($num[$i], $char2num)) {
                        $flag = false;
                        break;
                    }
                    $acc += ($char2num[$num[$i]] * pow(2, $i));
                } else {
                    $acc += $num[$i] * pow(2, $i);
                }
            }
        }
        if ($flag) {
            $rem = $acc % 11;
            if ($rem == 10) $rem = 0;
            if (strlen($mark) == 11 && $num[10] == $rem) {
                return true;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    /**
     * call component to check is certicate exist or not for a company.
     * 
     */
    private function isCertificate($companyId, $productId = 1, $commodityId = '')
    {
        return Yii::$app->issueCertificate->isCompanyRateExist($companyId, $productId, $commodityId);
    }

    public function actionEditpremium()
    {
        $aRequest = Yii::$app->request->post();
        $insurnceProductType = CargoForm::CONTAINER_PRODUCT_ID;
        $billingType = isset($aRequest['billing_type']) ? $aRequest['billing_type'] : '';
        $sez = isset($aRequest['sez']) ? $aRequest['sez'] : 0;
        $isOdc = isset($aRequest['isOdc']) ? $aRequest['isOdc'] : 0;
        $isW2w = isset($aRequest['w2w']) ? $aRequest['w2w'] : 0;
        $countryType = isset($data['country_type']) ? $data['country_type'] : '';
        if (isset($aRequest['product_type']) && strtolower($aRequest['product_type']) == 'noncontainer') {
            $insurnceProductType = CargoForm::NON_CONTAINER_PRODUCT_ID;
            $commodityModel = new \common\models\Commodity();
            $commodityId = $commodityModel->getIdByCommodity($aRequest['commodity']);
            $commodity = $aRequest['commodity'];
            $user = $this->isCertificate(Yii::$app->user->identity->company_id, $insurnceProductType, $commodityId);
        } else {
            $insurnceProductType =  1;
            $commodity = $aRequest['commodity'];
            $user = $this->isCertificate(Yii::$app->user->identity->company_id, $insurnceProductType);
        }

        // $transitTypeModel = new \common\models\TransitType();
        // $transitTypeId = $transitTypeModel->getIdByTransitType($aRequest['transitType']);
        // $transitModeModel = new \common\models\TransitMode();
        // $transitModeId = $transitModeModel->getIdByTransitMode($aRequest['transitMode']);
        // $productModel = new \common\models\InsuranceProduct();
        $w2wCheck = 0;
        $coverageStr = isset($aRequest['coverage']) ? $aRequest['coverage'] : '';
        $aCoverage = explode('+', $coverageStr);
        if (isset($aRequest['w2w']) && $aRequest['w2w'] == 1) {
            $w2wCheck = 1;
        }

        $isOdc = 0;
        if (isset($aRequest['isOdc']) && $aRequest['isOdc'] == 1) {
            $isOdc = 1;
        }

        $userRate = Yii::$app->commonutils
            ->getAllRates(
                $insurnceProductType,
                $commodity,
                $aCoverage[0],
                $aRequest['transitType'],
                $aRequest['transitMode'],
                $w2wCheck,
                $isOdc,
                false,
                1,
                '',
                '',
                $countryType,
                0,
                \Yii::$app->session->get('company.war_exclustion')
            );
        if ($userRate == false) {
            $aPremium['status'] = 'error';
            $aPremium['premium'] = 'Net premium can not be calculated.';
            $aPremium['service_tax_amount'] = 0;
            $aPremium['total_premium'] = 0;
            \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
            return [
                'premium' => $aPremium,
            ];
        }
        $minUsrPrm = round($aRequest['sum_insured'] * $userRate / 100);
        $maxUsrPrm = round($aRequest['sum_insured'] * $user->user_max_rate / 100);
        $minPrm = 0;
        if ($minUsrPrm < $user->user_premium) {
            $minPrm = $user->user_premium;
        } else {
            $minPrm = $minUsrPrm;
        }

        $maxPrm = 0;
        if ($maxUsrPrm < $user->user_premium) {
            $maxPrm = $user->user_premium;
        } else {
            $maxPrm = $maxUsrPrm;
        }
        $aPremium['status'] = 'error';
        if (!preg_match('#^\d+(?:\.\d{1,2})?$#', $aRequest['premium'])) {
            $aPremium['premium'] =  "Invalid premium.";
        } else {
            if ($aRequest['premium'] < $minPrm) {
                $aPremium['premium'] = "Net premium can not be less than $minPrm.";
            } else if ($aRequest['premium'] > $maxPrm && Yii::$app->params['maxPremium'] == 1) {
                $aPremium['premium'] = "Net premium can not be greater than $maxPrm.";
            } else {
                $aPremium['status'] = 'success';
                $aPremium['premium']['premium'] = $aRequest['premium'];
                $serviceTaxAmount = $this->getServiceTax($aRequest['premium'], $aRequest['billing_state']);
                $objGst = \yii::$app->gst->getGstProductWise(Yii::$app->params['insuranceproductName']);
                if ($objGst->ncc_cess_rate > 0) {
                    $serviceTaxAmount = $serviceTaxAmount + round($objGst->ncc_cess_rate * $aRequest['premium'] / 100);
                }
                if ($sez) {
                    $serviceTaxAmount = 0;
                }
                $aPremium['premium']['service_tax_amount'] =  number_format($serviceTaxAmount, 2, '.', '');
                $stmpduty = 0;
                if (isset($aRequest['stamp_duty'])) {
                    $stmpduty = $aRequest['stamp_duty'];
                    $aPremium['premium']['stamp_duty_amount'] = number_format($aRequest['stamp_duty'], 2, '.', '');
                }
                $aPremium['premium']['total_premium'] = number_format(round($aRequest['premium'] +
                    $serviceTaxAmount + $stmpduty), 2, '.', '');
            }
        }

        \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
        return [
            'premium' => $aPremium,
        ];
    }

    public function actionTest()
    {
        $payment = new \common\models\Payment();
        $payment->product_id = 1;
        $payment->payment_gateway_id = 1;
        $payment->payment_type_id = 2;
        $payment->payment_mode_id = 5;
        $payment->invoice_number = 1097;
        $payment->transaction_date = date('Y-m-d H:i:s');
        $payment->payment_amount = 50;
        $payment->payment_ref_no = 'test234';
        $payment->created_at = date('Y-m-d H:i:s');
        $payment->modified_at = date('Y-m-d H:i:s');
        $payment->status = 'success';
        $payment->remark = 'test';
        $payment->policy_detail_id = 13;

        $payment->save(false);
        //        echo "<pre>";
        //        print_r($payment);
        //        die;
    }

    public function actionCheckcargocertificate()
    {
        //        if (Yii::$app->request->isAjax) {
        //            try {
        $aRequest = Yii::$app->request->post();
        echo '';
        $insurnceProductType = CargoForm::NON_CONTAINER_PRODUCT_ID;
        $commodity = isset($aRequest['commodity']) ? $aRequest['commodity'] : '';
        $commodityModel = new \common\models\Commodity();
        $commodityId = $commodityModel->getIdByCommodity($commodity);
        $aRate = $this->isCertificate(Yii::$app->user->identity->company_id, $insurnceProductType, $commodityId);
        if ($aRate) {
            echo 'true';
        } else {
            echo 'false';
        }
        //            } catch (\Exception $ex) {
        //
        //            }
        //        }
    }

    public function getUserDetailByCompanyId($companyId)
    {
        $usrMdl = new \common\models\User();
        return $usrMdl->getCompanyAdminInfoByCompanyId($companyId);
    }

    public function actionGetuserdetailsbyparty()
    {
        try {
            $aRequest = Yii::$app->request->post();
            $cntctMdl = new \common\models\DgnoteUserContacts();
            $data = $cntctMdl->getUserContactDetailsByParty(1, Yii::$app->user->identity->id, $aRequest['q']);
            \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
            return [
                'contact' => $data,
            ];
        } catch (\Exception $ex) {
        }
    }

    public function actionGetbillingdetailsbyid()
    {
        if (Yii::$app->request->isAjax) {
            try {
                $aRequest = Yii::$app->request->post();
                $cntctMdl = new \common\models\DgnoteUserContacts();
                $data = $cntctMdl->getBillingDetailsById($aRequest['id'], Yii::$app->user->identity->id);
                \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
                return [
                    'details' => $data,
                ];
            } catch (\Exception $ex) {
            }
        }
    }

    private function getSurveyoragent($aRequest, $commodity, $country, $transitType, $saleTerms)
    {
        $resutlArray = [];
        if (!empty($aRequest['CargoForm']['surveyor_city']) && !empty($aRequest['CargoForm']['surveyor_agent'])) {
            $resutlArray['surveyor_city'] = $aRequest['CargoForm']['surveyor_city'];
            $resutlArray['surveyor_address'] = $aRequest['CargoForm']['surveyor_address'];
            $resutlArray['surveyor_agent'] = $aRequest['CargoForm']['surveyor_agent'];
            $resutlArray['surveyor_id'] = $aRequest['CargoForm']['surveyor_id'];
            return $resutlArray;
        }
        $productId = 2;

        $countryObj = \common\models\Country::find()->where(['name' => $country])->one();
        $countryId = $countryObj->id;
        $flag = true;
        $isCheck = $this->isCertificate(Yii::$app->user->identity->company_id, $productId, $commodity);
        if ($isCheck) {

            // sale term id
            $aExpolode = explode('-', $saleTerms);
            if (strpos(trim($aExpolode[0]), '/')) {
                $aExpolode1 = explode('/', $aExpolode[0]);
                $saleTerm = trim($aExpolode1[0]);
            } else {
                $saleTerm = trim($aExpolode[0]);
            }
            $saleTerm = \common\models\SaleTerms::find()->where(['like', 'term', $saleTerm])->one();
            // agent value
            $certificateAgent = \common\models\CertificateAgent::find()->where(['transit_type_id' => $transitType, 'term_sale_id' => $saleTerm->id])->one();
            if ($certificateAgent->display_value == 2) {
                $flag = true;
            } elseif ($certificateAgent->display_value == 1) {
                $countryId = 174;
                $flag = true;
            } else {
                $flag = false;
            }
        }
        $data = array();
        if ($flag) {
            $query = \common\models\Country::find();
            $query->select([
                'mi_settling_agent.id as id', 'mi_settling_agent.city as city',
                'mi_settling_agent.address as address', 'mi_settling_agent.name as name'
            ])
                ->joinWith(['survyoeragent'])
                ->where(['country_id' => $countryId]);
            $command = $query->createCommand();
            $data = $command->queryOne();
            $resutlArray['surveyor_city'] = $data['city'];
            $resutlArray['surveyor_address'] = $data['address'];
            $resutlArray['surveyor_agent'] = $data['name'];
            $resutlArray['surveyor_id'] = $data['id'];
            return $resutlArray;
        }

        return $resutlArray;
    }

    /**
     * Check approved country and display clauses on Popup..
     * @param type $country
     * @param type $country_name
     * @param type $type
     */
    public function actionCheckApprovedCountry($country, $country_name, $type, $flag = 0)
    {
        if (Yii::$app->request->isAjax) {
            $company_id = Yii::$app->user->identity->company_id;
            $is_country = $type;
            $returnObj = \common\models\Country::checkSanctionCountryByCompany(Yii::$app->user->identity->company_id, $country);
            $countryObj = \common\models\Country::findOne($country);
            if ($type == 0) {
                echo $this->renderPartial(
                    '_offline_popup_clauses.php',
                    ['country_name' => $country_name, 'is_country' => $is_country, 'flag' => $flag]
                );
            } else {
                if ($returnObj) {
                    echo 'true';
                } else {
                    echo $this->renderPartial(
                        '_offline_popup_clauses.php',
                        [
                            'country_name' => $country_name, 'is_country' => $is_country, 'flag' => $flag, 'countryType' => $countryObj->country_category
                        ]
                    );
                }
            }
            //            die();
        }
    }

    public function actionOfflineEdit($cargotype = 'import', $id = '')
    {

        $quoteObj = \common\models\Quotation::findOne(['id' => $id]);
        if ($quoteObj->offline_status == 'approved') {
            $accountbalance = new \common\models\UserAccountBalance();
            $balance = $accountbalance->getUserAccountBalance(Yii::$app->user->identity->id);
            if ($id) {
                $cargoModel = new CargoForm();
                $cargoModel->setCargo($id, true);
                $cargoModel->billing_state = $quoteObj->billing_state;
            } else {
                $cargoModel = new CargoForm();
            }
            $cargoModel->setScenario($cargotype);

            try {

                Yii::$app->session->removeFlash('success');
                $objUsr = $this->getUserDetailByCompanyId(Yii::$app->user->identity->company_id);
                if (!isset($objUsr->pincode)) {
                    Yii::$app->getSession()->addFlash('error', 'There is some issue.Please contact with admin');
                    return $this->redirect(['user/policy']);
                }
                if ($cargoModel->load(Yii::$app->request->post())) {
                    $aRequest = Yii::$app->request->post();
                    $quoteObj->party_name = $aRequest['CargoForm']['party_name'];
                    $quoteObj->gstin = $aRequest['CargoForm']['gstin'];
                    $quoteObj->seller_details = $aRequest['CargoForm']['seller_details'];
                    $quoteObj->buyer_details = $aRequest['CargoForm']['buyer_details'];
                    $quoteObj->receipt_no = $aRequest['CargoForm']['receipt_no'];
                    $quoteObj->authority_name = $aRequest['CargoForm']['authority_name'];
                    $quoteObj->authority_detail = $aRequest['CargoForm']['authority_detail'];
                    $quoteObj->reference_no = $aRequest['CargoForm']['reference_no'];
                    $quoteObj->additional_details = $aRequest['CargoForm']['additional_details'];
                    $quoteObj->receipt_date = !empty($aRequest['CargoForm']['receipt_date']) ? date('Y-m-d',  strtotime($aRequest['CargoForm']['receipt_date'])) : '';
                    if ($quoteObj->save(false, [
                        'party_name', 'gstin', 'seller_details', 'buyer_details', 'receipt_no',
                        'authority_name', 'authority_detail', 'receipt_date', 'additional_details', 'reference_no'
                    ])) {
                        Yii::$app->session->set('user.quote_id', $quoteObj->id);
                        if ($quoteObj->is_uploaded == 1) {
                            return $this->redirect(['open-policy/confirmation']);
                        }
                        return $this->redirect("confirmation");
                    }
                }
            } catch (\Exception $e) {
                //                echo "<pre>";   print_r($e);    die;
            }

            $labelNo = $labelDt = $labelNm = $lableDtl = "";
            switch ($quoteObj->transit_mode) {
                case "Sea":
                    $labelNo  = "BL No";
                    $labelDt  = "BL Date";
                    $labelNm  = "Vessel Name";
                    $lableDtl  = "Vessel Details";
                    break;
                case "Air":
                    $labelNo  = "AWB No";
                    $labelDt  = "AWB Date";
                    $labelNm  = "Airline Name";
                    $lableDtl  = "Airline Details";
                    break;
                case "Rail":
                    $labelNo  = "RR No";
                    $labelDt  = "RR Date";
                    $labelNm  = "Rail Authority Name";
                    $lableDtl  = "Rail Authority Details";
                    break;
                case "Road":
                    $labelNo  = "LR No";
                    $labelDt  = "LR Date";
                    $labelNm  = "Transport Name";
                    $lableDtl  = "Transport Details";
                    break;
                case "Courier":
                    $labelNo  = "Receipt No";
                    $labelDt  = "Receipt Date";
                    $labelNm  = "Courier Name";
                    $lableDtl  = "Courier Details";
                    break;
                case "Post":
                    $labelNo  = "Receipt No";
                    $labelDt  = "Receipt Date";
                    $labelNm  = "Postal Authority Name";
                    $lableDtl  = "Postal Details";
                    break;
                default:
                    $labelNo  = "BL No";
                    $labelDt  = "BL Date";
                    $labelNm  = "Vessel Name";
                    $lableDtl  = "Vessel Details";
                    break;
            }
            switch ($cargotype) {
                case 'import':
                    $type = 'edit_import';
                    break;
                case 'export':
                    $type = 'edit_export';
                    break;
                case 'inland':
                    $type = 'edit_inland';
                    break;
            }
            //            $type = ($cargotype=='import') ? 'edit_import' : 'edit_export';
            //        $objUser = $cargoModel->getUserFlag(Yii::$app->user->identity->company_id);
            $backDate = Yii::$app->params['allowBackDays'];
            //         echo '<pre>';
            //                print_r($cargoModel);die;
            return $this->render($type, [
                'model' => $cargoModel,
                'balance' => $balance,
                'cargotype' => $cargotype,
                'id' => $id,
                'backDate' => $backDate,
                'labelNo' => $labelNo,
                'labelDt' => $labelDt,
                'labelNm' => $labelNm,
                'lableDtl' => $lableDtl,
                'aResult' => $quoteObj,
                'container' => [],
                //            'objUser' => $objUser
            ]);
        } else {

            Yii::$app->session->setFlash('error', 'Policy purchased already');
            return $this->redirect("/insurance/user/policy");
        }
    }

    /**
     * To download offline policy's uploaded files.
     * @param type $id
     * @param type $type
     * @return type
     */
    public function actionOfflineDownload($id, $type = 'invoice')
    {
        $path = Yii::$app->params['uploadPath'] . "Offline/$id/";
        $objQuotation = \common\models\Quotation::find()->where(['id' => $id])->one();
        if ($objQuotation) {
            if ($type == 'invoice') {
                $path = $path . 'uploadInvoice/';
                $filename = $objQuotation->upload_invoice;
            } elseif ($type == 'offlne_format') {
                $path = $path . 'uploadOfflineFormat/';
                $filename = $objQuotation->upload_offline_format;
            } elseif ($type == 'survey_report') {
                $path = $path . 'SurveyReport/';
                $filename = $objQuotation->survey_report;
            } elseif ($type == 'odcDetails') {
                $path = $path . 'OdcDetails/';
                $filename = $objQuotation->uploaded_odc_details;
            } else {
                $path = $path . 'uploadPackageList/';
                $filename = $objQuotation->upload_packing_list;
            }
        }
        if (file_exists($path . "/" . $filename)) {
            Yii::$app->response->sendFile($path . "/" . $filename);
        } else {
            $type = strtolower($objQuotation->transit_type);
            Yii::$app->session->setFlash('error', 'File does not exist!');
            return $this->redirect("/insurance/quotation/offline-edit?cargotype=$type&id=$id");
        }
    }

    /**
     * Send Mail for offline policy with attachment..
     * @param type $quoteId
     */
    public function sendMailForOfflineFormat($objQuote)
    {
        // sending mail to Admin...
        $company = $objQuote->user->company;

        // sending mail to user...
        // $subject = 'Offline Policy Approval Request | '.$objQuote->invoice_no;
        $typeOfMovment = ucfirst($objQuote->transit_type);
        $shortName = isset($objQuote->user->companyname->short_name) ?
            $objQuote->user->companyname->short_name : $objQuote->user->company;
        $subject = 'Insurance | ' . $typeOfMovment . ' | Policy Approval Request | ' . $objQuote->invoice_no . ' | ' . $shortName . ' | ' . date('d-m-Y', strtotime($objQuote->created_at));

        if ($objQuote->is_3rd_country == 1) {
            $country = $objQuote->origin_country . ' - ' . $objQuote->destination_country;
            // $subject = 'Insurance | 3rd Country Policy Approval Request | '.$objQuote->invoice_no.' | '.$shortName.' | '.date('d-m-Y',strtotime($objQuote->created_at));
        }
        $country = $amount = $subject1 = $label = $offline_policy_request_for = '';
        $maxAmount = \Yii::$app->params['maxInsurancePremium'];
        $maxSumInsured = '';
        //if($objQuote->is_uploaded == 1) {
        $objMasterPolicy = \common\models\CompanyPolicyCargoCertificate::checkBajajGroupExitOrNotWithInfo($objQuote->user->company_id);
        $maxSumInsured = isset($objMasterPolicy->masterPolicy->max_sum_insured) ?
            number_format($objMasterPolicy->masterPolicy->max_sum_insured, 2, '.', ',') : $maxAmount;
        //}


        if ($objQuote->country_offline == 1 && $objQuote->cr_2_offline == 1) {
            $country = (($objQuote->transit_type == 'Import') ?
                $objQuote->origin_country : $objQuote->destination_country);
            if ($objQuote->is_uploaded == 1) {
                $amount = number_format($objQuote->sum_insured, 2);
                $subject1 .= ' sum insured (INR)' . $amount;
                $offline_policy_request_for = "Sum insured greater than INR $maxSumInsured";
            } else {
                $amount = number_format($objQuote->sum_insured, 2);
                $subject1 .= ' sum insured (INR)' . $amount;
                $offline_policy_request_for = "Sum insured greater than INR $maxAmount CR";
            }
        } elseif ($objQuote->country_offline == 1) {
            $country = (($objQuote->transit_type == 'Import') ?
                $objQuote->origin_country : $objQuote->destination_country);
            $subject1 .= 'sanctioned country ' . $country;
            $offline_policy_request_for = 'Sanctioned country';
        } else {
            $amount = number_format($objQuote->sum_insured, 2);
            $subject1 .= 'sum insured (INR)' . $amount;
            if ($objQuote->is_uploaded == 1) {
                $offline_policy_request_for = "Sum insured greater than INR $maxSumInsured";
            } else {
                $offline_policy_request_for = "Sum insured greater than INR $maxAmount CR";
            }
        }
        $shortName = isset($objQuote->user->companyname->short_name) ?
            $objQuote->user->companyname->short_name : $objQuote->user->company;



        if ($objQuote->transit_type == 'Import') {
            $transit_type = 'Import';
            $country_new = $objQuote->origin_country;
            $label = 'Origin Country';
        } elseif ($objQuote->transit_type == 'Export') {
            $transit_type = 'Export';
            $country_new = $objQuote->destination_country;
            $label = 'Destination Country';
        } else {
            $transit_type = 'Other';
            $country_new = 'India';
            $label = 'Country';
        }

        $name = $objQuote->institution_name;
        $cargo = $objQuote->cargo_description;
        $terms_of_sale = $objQuote->terms_of_sale;
        $invoice_date = $objQuote->invoice_date;
        $coverage = $objQuote->coverage;
        $billing_state = $objQuote->billing_state;
        $gstin = $objQuote->gstin;
        $sum_insured = $objQuote->sum_insured;
        $premium = $objQuote->premium;
        $total_premium = $objQuote->total_premium;
        $service_tax_amount = $objQuote->service_tax_amount;
        $billing_name = $objQuote->party_name;



        $uploadInvoice = \Yii::$app->params['uploadPath'] . "Offline/" .
            $objQuote->id . "/uploadInvoice/" . $objQuote->upload_invoice;
        $attach = [];
        $i = 0;
        if (file_exists($uploadInvoice)) {
            $attach[$i] = $uploadInvoice;
            $i++;
        }
        $uploadPackageList = \Yii::$app->params['uploadPath'] . "Offline/" .
            $objQuote->id . "/uploadPackageList/" . $objQuote->upload_packing_list;
        if (file_exists($uploadPackageList)) {
            //            $mail1->attach($uploadPackageList);
            $attach[$i] = $uploadPackageList;
            $i++;
        }

        $oflinePolicyPath = $this->createExcelFile($objQuote);
        if (file_exists($oflinePolicyPath)) {
            $attach[$i] = $oflinePolicyPath;
            $i++;
        }


        $aEscMail = Yii::$app->commonMailSms->getEscallationSettings($objQuote->user->company_id, 1);
        \Yii::$app->commonMailSms->sendMail(
            $objQuote->id,
            1,
            'insurance_offline_format_for_user',
            $subject,
            [
                'country' => $country, 'amount' => $amount, 'shortName' => $shortName,
                'user' => $objQuote->user, 'subject1' => $subject1, 'offline_policy_request_for' =>
                $offline_policy_request_for, 'transit_type' => $transit_type, 'country_new' => $country_new,
                'label' => $label, 'name' => $name, 'cargo' => $cargo, 'terms_of_sale' => $terms_of_sale,
                'invoice_date' => $invoice_date, 'coverage' => $coverage, 'billing_state' => $billing_state,
                'gstin' => $gstin, 'sum_insured' => $sum_insured, 'premium' => $premium, 'total_premium' => $total_premium, 'service_tax_amount' => $service_tax_amount, 'billing_name' => $billing_name,
                'objQuote' => $objQuote, 'maxAmount' => $maxAmount, 'maxSumInsured' => $maxSumInsured
            ],
            [$objQuote->user->email],
            [],
            $attach,
            [],
            $aEscMail
        );

        /* Bajaj escaleation setting -- Garun Mishra*/
        \Yii::$app->commonMailSms->sendMail(
            $objQuote->id,
            1,
            'insurance_offline_format_for_bajaj',
            $subject,
            [
                'country' => $country, 'amount' => $amount, 'shortName' => $shortName,
                'user' => $objQuote->user, 'subject1' => $subject1, 'offline_policy_request_for' =>
                $offline_policy_request_for, 'transit_type' => $transit_type, 'country_new' => $country_new,
                'label' => $label, 'name' => $name, 'cargo' => $cargo, 'terms_of_sale' => $terms_of_sale,
                'invoice_date' => $invoice_date, 'coverage' => $coverage, 'billing_state' => $billing_state,
                'gstin' => $gstin, 'sum_insured' => $sum_insured, 'premium' => $premium, 'total_premium' => $total_premium, 'service_tax_amount' => $service_tax_amount, 'billing_name' => $billing_name,
                'objQuote' => $objQuote, 'maxAmount' => $maxAmount, 'maxSumInsured' => $maxSumInsured
            ],
            [],
            [],
            $attach
        );
    }

    /**
     * Generate excel file..
     * @param type $aRequest
     */
    public function createExcelFile($aRequest)
    {
        $new_upload_path = Yii::$app->params['uploadPath'] . "insurance/offline_format/$aRequest->id/";
        if (!is_dir($new_upload_path)) {
            mkdir($new_upload_path, 0777, true);
        }
        $invNo = str_replace("/", "-", $aRequest->invoice_no);
        $path = $new_upload_path . $invNo . "_Offline Bajaj Format.xlsx";
        $objPHPExcel = new \PHPExcel();

        // create sheet first..
        $sheetObj = $objPHPExcel->getActiveSheet();
        $sheetExcelObj = $objPHPExcel->setActiveSheetIndex(0);
        $objPHPExcel->getActiveSheet()->setTitle("Sheet1");
        $objPHPExcel->getDefaultStyle()->getFont()
            ->setName('Arial')
            ->setSize(10);
        $headerStyle =  [
            'fill' => [
                'type' => \PHPExcel_Style_Fill::FILL_SOLID,
                'color' => ['rgb' => '12216F']
            ],
            'font' => [
                'color' => ['rgb' => 'ffffff'],
                'text-algin' => 'center'
            ],
            'alignment' => [
                'horizontal' => \PHPExcel_Style_Alignment::HORIZONTAL_CENTER,
                'vertical' => \PHPExcel_Style_Alignment::VERTICAL_CENTER
            ]
        ];
        $sheetObj->getStyle('A2')->applyFromArray($headerStyle);
        $sheetExcelObj->setCellValue("A2", 'Details required for the approval '
            . 'from Allianz Group Compliance - MSU Guidelines for Export '
            . 'and/or Import to')
            ->setRightToLeft(false);

        $centerAlign =  [
            'alignment' => [
                'horizontal' => \PHPExcel_Style_Alignment::HORIZONTAL_CENTER,
                'vertical' => \PHPExcel_Style_Alignment::VERTICAL_CENTER
            ],
            'borders' => [
                'allborders' => [
                    'style' => \PHPExcel_Style_Border::BORDER_THIN
                ]
            ]
        ];

        $tblHeaderStyle =  [
            'fill' => [
                'type' => \PHPExcel_Style_Fill::FILL_SOLID,
                'color' => ['rgb' => 'ffffff']
            ],
            'font' => [
                'color' => ['rgb' => '000000'],
                'text-algin' => 'center',
                'type' => \PHPExcel_Style_Fill::FILL_SOLID,
            ],
            'alignment' => [
                'horizontal' => \PHPExcel_Style_Alignment::HORIZONTAL_CENTER,
                'vertical' => \PHPExcel_Style_Alignment::VERTICAL_CENTER
            ],
            'borders' => [
                'allborders' => [
                    'style' => \PHPExcel_Style_Border::BORDER_THIN
                ]
            ]
        ];

        $tblWhiteHeaderStyle =  [
            'font' => [
                'color' => ['rgb' => '000000'],
                'text-algin' => 'center',
                'type' => \PHPExcel_Style_Fill::FILL_SOLID,
            ],
            'alignment' => [
                'horizontal' => \PHPExcel_Style_Alignment::HORIZONTAL_CENTER,
                'vertical' => \PHPExcel_Style_Alignment::VERTICAL_CENTER
            ],
            'borders' => [
                'allborders' => [
                    'style' => \PHPExcel_Style_Border::BORDER_THIN
                ]
            ]
        ];

        $sheetObj->getColumnDimension('A')->setWidth('10');
        $sheetObj->getStyle('A4')->applyFromArray($tblWhiteHeaderStyle);
        $sheetObj->getStyle('A4')->getFont()->setBold(true);
        $sheetExcelObj->setCellValue('A4', 'Sr. No.');
        $sheetObj->getStyle('A5')->applyFromArray($centerAlign);
        $sheetExcelObj->setCellValue('A5', '1');

        $sheetObj->getColumnDimension('B')->setWidth('30');
        $sheetObj->getStyle('B4')->applyFromArray($tblHeaderStyle);
        $sheetObj->getStyle('B4')->getFont()->setBold(true);
        $sheetExcelObj->setCellValue('B4', 'Insured Name');
        $sheetObj->getStyle('B5')->applyFromArray($centerAlign);
        $sheetExcelObj->setCellValue('B5', $aRequest->institution_name);

        $sheetObj->getColumnDimension('C')->setWidth('20');
        $sheetObj->getStyle('C4')->applyFromArray($tblHeaderStyle);
        $sheetObj->getStyle('C4')->getFont()->setBold(true);
        $sheetExcelObj->setCellValue('C4', 'Type of Movement');
        $sheetObj->getStyle('C5')->applyFromArray($centerAlign);
        $sheetExcelObj->setCellValue('C5', $aRequest->transit_type);

        $sheetObj->getColumnDimension('D')->setWidth('20');
        $sheetObj->getStyle('D4')->applyFromArray($tblHeaderStyle);
        $sheetObj->getStyle('D4')->getFont()->setBold(true);
        $sheetExcelObj->setCellValue('D4', 'Consignee');
        $sheetObj->getStyle('D5')->applyFromArray($centerAlign);
        $sheetExcelObj->setCellValue('D5', $aRequest->buyer_details);

        $sheetObj->getColumnDimension('E')->setWidth('20');
        $sheetObj->getStyle('E4')->applyFromArray($tblHeaderStyle);
        $sheetObj->getStyle('E4')->getFont()->setBold(true);
        $sheetExcelObj->setCellValue('E4', 'Consignor');
        $sheetObj->getStyle('E5')->applyFromArray($centerAlign);
        $sheetExcelObj->setCellValue('E5', $aRequest->seller_details);

        $sheetObj->getColumnDimension('F')->setWidth('20');
        $sheetObj->getStyle('F4')->applyFromArray($tblHeaderStyle);
        $sheetObj->getStyle('F4')->getFont()->setBold(true);
        $sheetExcelObj->setCellValue('F4', 'INCO Terms');
        $sheetObj->getStyle('F5')->applyFromArray($centerAlign);
        $sheetExcelObj->setCellValue('F5', $aRequest->terms_of_sale);

        $sheetObj->getColumnDimension('G')->setWidth('20');
        $sheetObj->getStyle('G4')->applyFromArray($tblHeaderStyle);
        $sheetObj->getStyle('G4')->getFont()->setBold(true);
        $sheetExcelObj->setCellValue('G4', 'Origin');
        $sheetObj->getStyle('G5')->applyFromArray($centerAlign);
        $sheetExcelObj->setCellValue('G5', $aRequest->origin_country);

        $sheetObj->getColumnDimension('H')->setWidth('20');
        $sheetObj->getStyle('H4')->applyFromArray($tblHeaderStyle);
        $sheetObj->getStyle('H4')->getFont()->setBold(true);
        $sheetExcelObj->setCellValue('H4', 'Destination');
        $sheetObj->getStyle('H5')->applyFromArray($centerAlign);
        $sheetExcelObj->setCellValue('H5', $aRequest->destination_country);

        $sheetObj->getColumnDimension('I')->setWidth('20');
        $sheetObj->getStyle('I4')->applyFromArray($tblHeaderStyle);
        $sheetObj->getStyle('I4')->getFont()->setBold(true);
        $sheetExcelObj->setCellValue('I4', 'Transit Start Date');
        $sheetObj->getStyle('I5')->applyFromArray($centerAlign);
        $sheetExcelObj->setCellValue('I5', date('d-m-Y', strtotime($aRequest->coverage_start_date)));

        $sheetObj->getColumnDimension('J')->setWidth('20');
        $sheetObj->getStyle('J')->getAlignment()->setWrapText(true);
        $sheetObj->getStyle('J4')->applyFromArray($tblWhiteHeaderStyle);
        $sheetObj->getStyle('J4')->getFont()->setBold(true);
        $sheetExcelObj->setCellValue('J4', 'Detailed Cargo Description');
        $sheetObj->getStyle('J5')->applyFromArray($centerAlign);
        $sheetExcelObj->setCellValue('J5', $aRequest->cargo_description);

        $sheetObj->getColumnDimension('K')->setWidth('20');
        $sheetObj->getStyle('K4')->applyFromArray($tblWhiteHeaderStyle);
        $sheetObj->getStyle('K4')->getFont()->setBold(true);
        $sheetExcelObj->setCellValue('K4', 'Sum Insured in INR');
        $sheetObj->getStyle('K5')->applyFromArray($centerAlign);
        $sheetExcelObj->setCellValue('K5', $aRequest->sum_insured);

        $sheetObj->getColumnDimension('L')->setWidth('20');
        $sheetObj->getStyle('L4')->applyFromArray($tblWhiteHeaderStyle);
        $sheetObj->getStyle('L4')->getFont()->setBold(true);
        $sheetExcelObj->setCellValue('L4', 'Sum Insured in FC');
        $sheetObj->getStyle('L5')->applyFromArray($centerAlign);
        $sheetExcelObj->setCellValue('L5', $aRequest->invoice_currency . ' ' . $aRequest->invoice_amount);

        $sheetObj->getColumnDimension('M')->setWidth('25');
        $sheetObj->getStyle('M4')->applyFromArray($tblWhiteHeaderStyle);
        $sheetObj->getStyle('M4')->getFont()->setBold(true);
        $sheetExcelObj->setCellValue('M4', 'ROE (Rate of Exchange)');
        $sheetObj->getStyle('M5')->applyFromArray($centerAlign);
        $sheetExcelObj->setCellValue('M5', $aRequest->exchange_rate);

        $sheetObj->getStyle('N')->getAlignment()->setWrapText(true);
        $sheetObj->getColumnDimension('N')->setWidth('25');
        $sheetObj->getStyle('N4')->applyFromArray($tblWhiteHeaderStyle);
        $sheetExcelObj->getRowDimension('4')->setRowHeight(50);
        $sheetObj->getStyle('N4')->getFont()->setBold(true);
        $sheetExcelObj->setCellValue('N4', 'Whether exporter is agreeable for payment of claim in INR');
        $sheetExcelObj->getRowDimension('5')->setRowHeight(25);
        $sheetObj->getStyle('N5')->applyFromArray($centerAlign);
        $sheetExcelObj->setCellValue('N5', 'YES');


        $sheetObj->mergeCells('A2:N2');

        $objWriter = \PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel2007');
        $objWriter->save($path);

        // $writer = IOFactory::createWriter($objPHPExcel, 'Xlsx');
        // $writer->save($path);
        return $path;
    }

    public function actionCheckgstin($gstin, $sez = 0, $checksez = 0)
    {
        if (Yii::$app->request->isAjax) {
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
            //print_r($gstin);
            //$json = json_encode($gstin);        
            //echo $json;
            die;
        }
    }

    /**
     * To download Inco term..
     * @return type
     */
    public function actionDownloadIncoTerm()
    {
        $doc = Yii::$app->params['incoTermFile'];
        $path = Yii::$app->params['uploadPath'] . "IncoTerm/";
        $filename = $path . "/" . $doc;
        if (file_exists($filename)) {
            header('Content-type: application/pdf');
            header('Content-Disposition: inline; filename="' . $doc . '"');
            header('Content-Transfer-Encoding: binary');
            header('Content-Length: ' . filesize($filename));
            header('Accept-Ranges: bytes');
            @readfile($filename);
            exit();
        } else {
            Yii::$app->session->setFlash('error', 'File does not exist!');
            return $this->redirect('container');
        }
    }

    /**
     * To edit the survay..
     * @param type $id
     */
    public function actionSurvey($id)
    {
        $cargoModel = new \frontend\insurance\models\CertificateForm();
        $cargoModel->setSurvey($id);
        $quoteObj = \common\models\Quotation::findOne(['id' => $id]);
        $diff = date_diff(
            date_create(date('Y-m-d')),
            date_create(date('Y-m-d',  strtotime($quoteObj->coverage_start_date)))
        );
        if ($diff->days > 0 || (isset($quoteObj->survey->status) && $quoteObj->survey->request_type == 1)) {
            Yii::$app->session->setFlash('error', 'Please contact with admin to edit Survey.');
            return $this->redirect(["/user/policy"]);
        }
        if ($cargoModel->load(Yii::$app->request->post())) {
            $aRequest = Yii::$app->request->post('CertificateForm');
            $upload_invoice = \yii\web\UploadedFile::getInstance($cargoModel, 'upload_invoice');

            $upload_invoice = \yii\web\UploadedFile::getInstance($cargoModel, 'upload_invoice');
            $upload_packing_list = \yii\web\UploadedFile::getInstance($cargoModel, 'upload_packing_list');
            $upload_survey_report = \yii\web\UploadedFile::getInstance($cargoModel, 'survey_report');
            $cargoModel->upload_invoice = $upload_invoice != '' ? $upload_invoice->name : "";
            $cargoModel->upload_packing_list = $upload_packing_list != '' ? $upload_packing_list->name : "";
            $cargoModel->survey_report = $upload_survey_report != '' ? $upload_survey_report->name : "";
            if ($cargoModel->validate()) {
                $cargoModel->user_id = $quoteObj->user_id;
                $cargoModel->save(
                    $aRequest,
                    $upload_invoice,
                    $upload_packing_list,
                    $upload_survey_report
                );


                $attachMent = [];
                if (!empty($cargoModel->upload_invoice)) {
                    $new_upload_path = \Yii::$app->params['uploadPath'] . "Offline/" . $id . "/uploadInvoice/" . $cargoModel->upload_invoice;
                    if (file_exists($new_upload_path)) {
                        $attachMent[] = $new_upload_path;
                    }
                }
                if (!empty($cargoModel->upload_packing_list)) {
                    $new_upload_path = \Yii::$app->params['uploadPath'] . "Offline/" . $id . "/uploadPackageList/" . $cargoModel->upload_packing_list;
                    if (file_exists($new_upload_path)) {
                        $attachMent[] = $new_upload_path;
                    }
                }
                if (!empty($cargoModel->survey_report)) {
                    $new_upload_path = \Yii::$app->params['uploadPath'] . "Offline/" . $id . "/uploadSurveyReport/" . $cargoModel->survey_report;
                    if (file_exists($new_upload_path)) {
                        $attachMent[] = $new_upload_path;
                    }
                }

                $oflinePolicyPath = $this->createExcelFile($quoteObj);
                if (file_exists($oflinePolicyPath)) {
                    $attachMent[] = $oflinePolicyPath;
                }
                // send mail for user..
                $companyShrtName = isset($quoteObj->user->companyname->short_name) ?
                    $quoteObj->user->companyname->short_name : $quoteObj->user->company;
                $subject = 'Offline All Risk Policy Approval Request | ' . $companyShrtName;
                \Yii::$app->commonMailSms->sendMail(
                    $quoteObj->id,
                    1,
                    'insurance_baisc_to_all_risk',
                    $subject,
                    ['query' => $quoteObj],
                    [$quoteObj->user->email],
                    [],
                    $attachMent
                );
                Yii::$app->session->setFlash('success', 'Survey has been updated to ALL_RISK.');
                return $this->redirect(["/user/policy"]);
            } else {
                $aError = $cargoModel->getErrors();
                foreach ($aError as $key => $value) {
                    Yii::$app->session->setFlash('error', $value[0]);
                    break;
                }
            }
        }
        $accountbalance = new \common\models\UserAccountBalance();
        $balance = $accountbalance->getUserAccountBalance(Yii::$app->user->identity->id);

        $aCoverage = $cargoModel->getStaticCoverage(
            $quoteObj->commodity,
            $quoteObj->transit_type,
            $quoteObj->transit_mode
        );

        $aLabel = $this->getTransitModeLabel($quoteObj->transit_mode);
        $labelNo = $aLabel['labelNo'];
        $labelDt = $aLabel['labelDt'];
        $labelNm = $aLabel['labelNm'];
        $lableDtl = $aLabel['lableDtl'];
        $view = 'survey';
        if ($quoteObj->is_3rd_country == 1) {
            $view = 'survey_3rd_country';
        }
        return $this->render($view, [
            'aResult' => $quoteObj,
            'balance' => $balance,
            'model' => $cargoModel,
            'cargotype' => 'import',
            'labelNo' => $labelNo,
            'labelDt' => $labelDt,
            'labelNm' => $labelNm,
            'lableDtl' => $lableDtl,
            'aCoverage' => $aCoverage,
        ]);
    }

    public function actionBuySurvey($id)
    {
        $objSurvey = \common\models\InsuranceSurveyRequest::findOne([
            'quote_id' => $id,
            'status' => 1, 'user_id' => Yii::$app->user->identity->id, 'request_type' => 1
        ]);
        $accountbalance = new \common\models\UserAccountBalance();
        $balance = $accountbalance->getUserAccountBalance(Yii::$app->user->identity->id);
        if ($objSurvey) {
            $quoteObj = \common\models\Quotation::findOne(['id' => $id]);
            $quoteObj->setScenario('buy_survey');
            $newNetPremium = round($quoteObj->sum_insured * $objSurvey->user_rate / 100);
            if ($newNetPremium > $quoteObj->premium) {
                $quoteObj->premium = $newNetPremium;
                $aServiceTax = unserialize($quoteObj->service_tax_attributes);
                if (empty($aServiceTax['igst'])) {
                    $newServiceTax = ($newNetPremium * $aServiceTax['sgst_rate'] / 100 +
                        $newNetPremium * $aServiceTax['cgst_rate'] / 100);
                } else {
                    $newServiceTax = ($newNetPremium * $aServiceTax['igst_rate'] / 100);
                }
                $quoteObj->service_tax_amount = number_format($newServiceTax, 2);
                if ($quoteObj->is_sez == 1) {
                    $quoteObj->service_tax_amount = 0;
                    $newServiceTax = 0;
                }
                $quoteObj->total_premium = number_format(
                    round($newNetPremium + $newServiceTax + $quoteObj->stamp_duty_amount),
                    2
                );
            }
            Yii::$app->session->set('user.quote_id', $quoteObj->id);
            if ($quoteObj->load(Yii::$app->request->post())) {
                $aRequest = Yii::$app->request->post('Quotation');
                $newPremium = str_replace(",", "", $aRequest['total_premium']);
                $totalRemAmnt = $newPremium -
                    str_replace(",", "", $quoteObj->total_premium);
                //               echo $totalRemAmnt;die;
                if (!$this->checkCompanyCredit($balance, $totalRemAmnt)) {
                    Yii::$app->session->setFlash('error', 'Payment is declined due to insufficient credit limit, please contact DgNote Administrator!');
                } else {
                    if ($quoteObj->validate()) {

                        $objSez = $this->getSEZ();
                        $sezFlag = false;
                        if ($objSez->is_sez == 2 && $quoteObj->billing_detail == 2 && $quoteObj->is_sez == 1) {
                            $sezFlag = true;
                        } elseif ($objSez->is_sez == 1 && $quoteObj->billing_detail == 1 && $quoteObj->is_sez == 1) {
                            $sezFlag = true;
                        }
                        $objState = \common\models\DgnoteTrailerState::find()
                            ->where(['name' => $quoteObj->billing_state])->one();
                        if ($sezFlag) {
                            $serailze =
                                \yii::$app->gst->getSeralizedGSTWithOutRound(
                                    $quoteObj->premium,
                                    $newPremium,
                                    $objState->state_code
                                );
                            $aUnSerailize = unserialize($serailze);
                            $aUnSerailize['igst'] = 0;
                            $aUnSerailize['sgst'] = 0;
                            $aUnSerailize['cgst'] = 0;
                            $serviceTax = serialize($aUnSerailize);
                        } else {
                            $serviceTax =
                                \yii::$app->gst->getSeralizedGSTWithOutRound(
                                    $quoteObj->premium,
                                    $newPremium,
                                    $objState->state_code
                                );
                        }
                        $quoteObj->service_tax_attributes = $serviceTax;
                        $quoteObj->total_premium = $newPremium;
                        $quoteObj->premium = $aRequest['premium'];
                        $quoteObj->service_tax_amount = $aRequest['service_tax_amount'];
                        $quoteObj->dgnote_commission = $objSurvey->dgnote_rate;
                        if ($quoteObj->save(false, [
                            'service_tax_attributes', 'total_premium', 'service_tax_amount', 'dgnote_commission', 'premium'
                        ])) {
                            return $this->redirect("survey-confirmation");
                        }
                    } else {
                        $aError = $quoteObj->getErrors();
                        foreach ($aError as $key => $value) {
                            Yii::$app->session->setFlash('error', $value[0]);
                            break;
                        }
                    }
                }
            }
            $aLabel = $this->getTransitModeLabel($quoteObj->transit_mode);
            $labelNo = $aLabel['labelNo'];
            $labelDt = $aLabel['labelDt'];
            $labelNm = $aLabel['labelNm'];
            $lableDtl = $aLabel['lableDtl'];
            $view = 'buy_survey';
            if ($quoteObj->is_3rd_country == 1) {
                $view = 'buy_survey_3rd_country';
            }
            return $this->render($view, [
                'balance' => $balance,
                'model' => $quoteObj,
                'cargotype' => 'import',
                'labelNo' => $labelNo,
                'labelDt' => $labelDt,
                'labelNm' => $labelNm,
                'lableDtl' => $lableDtl,
                'flagSez' => 0
            ]);
        } else {
            Yii::$app->session->setFlash('error', 'Survey can not be edited.');
            return $this->redirect(["/user/policy"]);
        }
    }

    /**
     * Get label of transit mode
     * @param type $transitMode
     * @return string
     */
    private function getTransitModeLabel($transitMode)
    {
        $aLabel = [];
        switch ($transitMode) {
            case "Sea":
                $aLabel['labelNo']  = "BL No";
                $aLabel['labelDt']  = "BL Date";
                $aLabel['labelNm']  = "Vessel Name";
                $aLabel['lableDtl']  = "Vessel Details";
                break;
            case "Air":
                $aLabel['labelNo']  = "AWB No";
                $aLabel['labelDt']  = "AWB Date";
                $aLabel['labelNm']  = "Airline Name";
                $aLabel['lableDtl']  = "Airline Details";
                break;
            case "Rail":
                $aLabel['labelNo']  = "RR No";
                $aLabel['labelDt']  = "RR Date";
                $aLabel['labelNm']  = "Rail Authority Name";
                $aLabel['lableDtl']  = "Rail Authority Details";
                break;
            case "Road":
                $aLabel['labelNo']  = "LR No";
                $aLabel['labelDt']  = "LR Date";
                $aLabel['labelNm']  = "Transport Name";
                $aLabel['lableDtl']  = "Transport Details";
                break;
            case "Courier":
                $aLabel['labelNo']  = "Receipt No";
                $aLabel['labelDt']  = "Receipt Date";
                $aLabel['labelNm']  = "Courier Name";
                $aLabel['lableDtl']  = "Courier Details";
                break;
            case "Post":
                $aLabel['labelNo']  = "Receipt No";
                $aLabel['labelDt']  = "Receipt Date";
                $aLabel['labelNm']  = "Postal Authority Name";
                $aLabel['lableDtl']  = "Postal Details";
                break;
            default:
                $aLabel['labelNo']  = "BL No";
                $aLabel['labelDt']  = "BL Date";
                $aLabel['labelNm']  = "Vessel Name";
                $aLabel['lableDtl']  = "Vessel Details";
                break;
        }
        return $aLabel;
    }

    public function actionEditSurveyPremium()
    {
        $aRequest = Yii::$app->request->post();
        $objQuote = \common\models\Quotation::findOne($aRequest['id']);
        $sez = isset($aRequest['sez']) ? $aRequest['sez'] : 0;
        if (!empty($objQuote->survey_coverage) && $objQuote->survey_coverage == 'ALL_RISK') {
            $objSurvey = \common\models\InsuranceSurveyRequest::find()
                ->where(['quote_id' => $objQuote->id, 'request_type' => 1])->one();
        } else {
            $objSurvey = \common\models\InsuranceSurveyRequest::find()
                ->where(['quote_id' => $objQuote->id, 'request_type' => 2])->one();
        }
        $userRate = $objSurvey->user_rate;
        $minUsrPrm = round($objQuote->sum_insured * $userRate / 100);
        $maxUsrPrm = round($objQuote->sum_insured * $objQuote->survey->max_user_rate / 100);
        $minPrm = 0;
        if ($minUsrPrm == 0 || $minUsrPrm < $objQuote->premium) {
            $minPrm = $objQuote->premium;
        } else {
            $minPrm = $minUsrPrm;
        }

        if ($maxUsrPrm == 0 || $maxUsrPrm < $objQuote->premium) {
            $maxUsrPrm = $objQuote->premium;
        }

        $aPremium['status'] = 'error';
        if (!preg_match('/^\d+$/', $aRequest['premium'])) {
            $aPremium['premium'] =  "Invalid premium.";
        } else {

            if ($objQuote->is_odc == 1 && $minUsrPrm > $objQuote->premium) {
                $minPrm = $minUsrPrm;
            }
            if ($aRequest['premium'] < $minPrm) {
                $aPremium['premium'] = "Net premium can not be less than $minPrm.";
            } else if ($aRequest['premium'] > $maxUsrPrm && Yii::$app->params['maxPremium'] == 1) {
                $aPremium['premium'] = "Net premium can not be greater than $maxUsrPrm.";
            } else {
                $aPremium['status'] = 'success';
                $aPremium['premium']['premium'] = $aRequest['premium'];
                $objState = \common\models\DgnoteTrailerState::find()
                    ->where(['name' => $objQuote->billing_state])->one();
                //                $objUsr = $this->getUserDetailByCompanyId(Yii::$app->user->identity->company_id);
                $serviceTaxAmount = $this->getServiceTax($aRequest['premium'], $objState->state_code);
                $objGst = \yii::$app->gst->getGstProductWise(Yii::$app->params['insuranceproductName']);
                if ($objGst->ncc_cess_rate > 0) {
                    $serviceTaxAmount = $serviceTaxAmount + round($objGst->ncc_cess_rate * $aRequest['premium'] / 100);
                }
                //                $flagSez = $this->checkSEZ();
                if ($sez) {
                    $serviceTaxAmount = 0;
                }
                $aPremium['premium']['service_tax_amount'] =  number_format($serviceTaxAmount, 2, '.', '');
                $stmpduty = 0;
                if (isset($aRequest['stamp_duty'])) {
                    $stmpduty = $aRequest['stamp_duty'];
                    $aPremium['premium']['stamp_duty_amount'] = number_format($aRequest['stamp_duty'], 2, '.', '');
                }
                $aPremium['premium']['total_premium'] = number_format(round($aRequest['premium'] + $serviceTaxAmount + $stmpduty), 2, '.', '');
            }
        }

        \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
        return [
            'premium' => $aPremium,
        ];
    }


    public function actionSurveyConfirmation()
    {
        $userId = \yii::$app->user->id;
        $companyId = \yii::$app->user->identity->company_id;
        $quoteId = Yii::$app->session->get('user.quote_id');
        $query = new \common\models\Quotation();
        $data = $query->getQuote($quoteId, $userId);

        $aContainer = [];
        $blanceModel = new \common\models\UserAccountBalance();
        $balance = $blanceModel->getUserAccountBalance($userId);

        $objQuote = \common\models\Quotation::findOne($quoteId);
        $objPayment = \common\models\Payment::findOne([
            'invoice_number' => $quoteId,
            'user_id' => $userId, 'payment_type_id' => 1, 'product_id' => 1
        ]);
        $remainingAmount = $objQuote->total_premium - $objPayment->payment_amount;

        //        $totalRemAmnt = str_replace(",", "", $aRequest['total_premium']) - 
        //                       str_replace(",", "", $quoteObj->total_premium);
        if (Yii::$app->request->post()) {
            $aRequest = Yii::$app->request->post();
            $quoteId = $aRequest['quote_id'];
            // update payment amount..for user
            $objPayment->payment_amount = $aRequest['paid_amount'];
            $objPayment->save(false, ['payment_amount']);
            $aBajajCal = unserialize($objQuote->bajajPremium->bajaj_calculation);
            $preBajajAmount = $aBajajCal['bajaj_total_amount'];
            // update bajaj permium segregation table...
            $policyDetailId = Yii::$app->issueCertificate->saveAmountCalculationWithCoverage($quoteId);
            $objBajaPre = \common\models\DgnoteBajajPremium::findOne($policyDetailId);
            $aBajajCal = unserialize($objBajaPre->bajaj_calculation);
            $postBajajAmount = $aBajajCal['bajaj_total_amount'];
            $newBajajAmnt = $postBajajAmount - $preBajajAmount;
            $blanceModel->updateCompanyAccountBalance(
                $objQuote->directpolicy->policy_company_id,
                $newBajajAmnt,
                1
            );
            // TDS amount Calculation..
            $objCreditPayment = \common\models\Payment::findOne([
                'invoice_number' => $quoteId,
                'user_id' => $userId, 'payment_type_id' => 2, 'product_id' => 1
            ]);
            $dgnoteBajajPrmMdl = \common\models\DgnoteBajajPremium::findOne(['quote_id' =>
            $quoteId]);
            $tdsAmount  = $newTdsAmount = $oldTds = 0;
            if ($objQuote->is_uploaded == 0) {
                if (!$objCreditPayment) {
                    $objPaymntCntrl = new PaymentController('quotation', 'insurance');
                    // add Tds after generate certificate..
                    $newTdsAmount = $objPaymntCntrl->addTdsAmount(
                        $quoteId,
                        $objQuote->directpolicy->id,
                        $companyId,
                        $userId,
                        $objQuote->invoice_id
                    );
                } else {
                    $companyObj = \common\models\DgnoteCompanies::findOne(\yii::$app->user->identity->company_id);
                    $newTdsAmount = round($objQuote->premium * $companyObj->insurance_tds / 100);
                    $oldTds = $objCreditPayment->payment_amount;
                    $objCreditPayment->payment_amount = $newTdsAmount;
                    $objCreditPayment->save(false, ['payment_amount']);
                }
            }

            $remainingAmount = $newTdsAmount - $oldTds - $remainingAmount;
            // update user account balance...
            $userAccountModel = new \common\models\UserAccountBalance();
            $userAccountModel->updateAccountBalanceForCredit(
                $companyId,
                $remainingAmount,
                1,
                $userId,
                'topup'
            );

            // update invoice details..
            $objInvoiceDetails = \common\models\InvoiceDetails::findOne(['inv_id' => $objQuote->invoice_id]);
            $original_array = unserialize($objQuote->service_tax_attributes);
            $objInvoiceDetails->sgst_rate = $original_array['sgst_rate'];
            $objInvoiceDetails->cgst_rate = $original_array['cgst_rate'];
            $objInvoiceDetails->igst_rate = $original_array['igst_rate'];
            $objInvoiceDetails->sgst_amt = $original_array['sgst'];
            $objInvoiceDetails->cgst_amt = $original_array['cgst'];
            $objInvoiceDetails->igst_amt = $original_array['igst'];
            $objInvoiceDetails->modified_at = date('Y-m-d H:i:s');
            $objInvoiceDetails->amount = $objQuote->premium;
            $objInvoiceDetails->tot_amount = $objQuote->total_premium;
            $objInvoiceDetails->save();
            $objInvoice = \common\models\DgnoteInvoice::findOne($objQuote->invoice_id);
            $objInvoice->total_inv_amnt = $objQuote->total_premium;
            $objInvoice->save(false, ['total_inv_amnt']);

            // Update Bajaj Ledger..
            $objBajajLedger = \frontend\insurance\models\BajajLedger::findOne(['invoice_number' => $quoteId]);
            $objBajajLedger->payment_amount = $dgnoteBajajPrmMdl->bajaj_premium;
            $objBajajLedger->modified_at = date('Y-m-d H:i:s');
            $objBajajLedger->save(false, ['payment_amount', 'modified_at']);
            Yii::$app->session->setFlash('success', 'Your policy has been updated.');

            $objSurveyObj = \common\models\InsuranceSurveyRequest::findOne(
                ['quote_id' => $objQuote->id, 'request_type' => 1]
            );
            $objSurveyObj->status = 3;
            $objSurveyObj->save(false, ['status']);
            return $this->redirect(['user/policy']);
        }


        $objCredit = \common\models\TransportCredit::findOne([
            'company_id'
            => \yii::$app->user->identity->company_id, 'product_id' => 1, 'status' => 'Active'
        ]);
        $credit = false;
        if ($objCredit && $this->checkCompanyCredit($balance, $remainingAmount)) {
            $credit = true;
        }
        //        echo $credit;die;
        return $this->render('survey_confirmation', [
            'data' => $data,
            'userbalance' => $balance,
            'aContainer' => $aContainer,
            'balance' => $balance,
            'product' => 2,
            'remainingAmount' => $remainingAmount,
            'cargotype' => 'import',
            'objCredit' => $objCredit,
            'credit' => $credit
        ]);
    }

    /*
     * Preview of Certificate..
     */
    public function actionPreview()
    {
        if (Yii::$app->request->post()) {
            $aCertificate = !empty(Yii::$app->request->post('CargoForm')) ?
                Yii::$app->request->post('CargoForm') : Yii::$app->request->post('ContainerForm');
            $resutlArray = [];
            if ($aCertificate['transit_type'] == 'Export' && !empty($aCertificate['surveyor_id'])) {
                $query = \common\models\Country::find();
                $query->select([
                    'mi_settling_agent.id as id', 'mi_settling_agent.city as city',
                    'mi_settling_agent.address as address',
                    'mi_settling_agent.name as name', 'mi_country.name as country'
                ])
                    ->joinWith(['survyoeragent'])
                    ->where(['mi_settling_agent.id' => $aCertificate['surveyor_id']]);
                $command = $query->createCommand();
                $data = $command->queryOne();
                $resutlArray['surveyor_city'] = $data['city'];
                $resutlArray['surveyor_address'] = $data['address'];
                $resutlArray['surveyor_agent'] = $data['name'];
                $resutlArray['surveyor_id'] = $data['id'];
                $resutlArray['country'] = $data['country'];
            }

            $countryFlag = false;
            $cntryCat = '';
            $isODC = isset($aCertificate['is_odc']) ? $aCertificate['is_odc'] : '';
            $isBarge = isset($aCertificate['odc_barge_shipement']) ? $aCertificate['odc_barge_shipement'] : '';
            if (($aCertificate['transit_type'] == 'Import' || $aCertificate['transit_type'] == 'Export')) {
                if ($aCertificate['transit_type'] == 'Import') {
                    $country = $aCertificate['origin_country'];
                } else {
                    $country = $aCertificate['destination_country'];
                }
                $cntryMdl = $cntryMdl = \common\models\Country::find()->where(['name' => trim($country)])->one();
                if ($cntryMdl && ($cntryMdl->country_category == 'S' || $cntryMdl->country_category == 'H')) {
                    $countryFlag = true;
                }
                $cntryCat = isset($cntryMdl->country_category) ? $cntryMdl->country_category : '';
            }
            $stringPremium = \Yii::$app->numbrString->convertNumberToSting($aCertificate['total_premium']);
            if (!empty(Yii::$app->request->post('CargoForm'))) {
                $objClause = new \common\models\ClauseMatrix();
                $aClauses = $objClause->getClauseListing(
                    $aCertificate['transit_type'],
                    $aCertificate['transit_mode'],
                    $aCertificate['commodity'],
                    $aCertificate['w2w'],
                    $aCertificate['coverage'],
                    $cntryCat,
                    $isODC,
                    $isBarge
                );
                $termOfSale = isset($aCertificate['terms_of_sale']) ? $aCertificate['terms_of_sale'] : '';
                $objCoverageLocation = Yii::$app->inscommonutils->getCoverageLocation(
                    $aCertificate['transit_type'],
                    $termOfSale,
                    $aCertificate['w2w']
                );
                $html = $this->renderPartial('cargo_certificate', [
                    'aClauses' => $aClauses,
                    'aRequest' => $aCertificate, 'countryFlag' => $countryFlag,
                    'stringPremium' => $stringPremium, 'resutlArray' => $resutlArray, 'objCoverageLocation' => $objCoverageLocation
                ]);
            } else {
                $objCommodity = \common\models\Commodity::find()->where(['code' =>
                $aCertificate['commodity']])->one();
                $aContainer = Yii::$app->request->post('InsuranceContainer');
                $html = $this->renderPartial('certificate', [
                    'aRequest' => $aCertificate,
                    'aContainer' => $aContainer, 'stringPremium' => $stringPremium,
                    'objCommodity' => $objCommodity
                ]);
            }
            // $mpdf = new \mPDF('c', 'A4', '', '', 10, 10, 35, 25, 5, 5);
            $mpdf = new Mpdf([
                'mode' => 'c',
                'format' => 'A4',
                'margin_top' => 35,
                'margin_bottom' => 25,
                'margin_left' => 10,
                'margin_right' => 10,
                'margin_header' => 5,
                'margin_footer' => 5,
                'tempDir' => __DIR__ . '/custom/temp/dir/path'
            ]);
            $header = '<div class="logo"><img src="image/bajaj_logo.png" width="120" height="30" alt=""/></div>
                            <div class="center" style="margin-left:11px;margin-right:11px;">
                                    <span style="font-family: Calibri;font-size: 12px;    font-style: normal;font-variant: normal;line-height: 14pt;padding: 0;margin: 0;margin-bottom: 5px;">BAJAJ ALLIANZ GENERAL INSURANCE COMPANY LIMITED</span>
                                    <h4>(A Company incorporated under Indian Companies Act, 1956 and licensed by Insurance Regulatory and Development Authority of India [IRDAI] vide Regd. No.113) : <br>
                                            Regd. Office: GE Plaza, Airport Road, Yerwada, Pune – 411006 (India)</h4>
                                    <h3>    Marine Insurance Certificate <span>UIN.IRDAN113RP0024V01200102</span></h3>
                            </div><div style="margin-left:34%;margin-right:11px;padding-top:-5px;">
                            <span style="font-family: Calibri;font-size: 9px;font-weight:bold;font-style: normal;font-variant: normal;padding: 0;margin: 0;">(Transit Insurance Certificate)</span>
                            </div>';

            $footer = '<div class="footer"  style="margin-left:11px;margin-right:11px;">
                                <table class="tblData2" width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tbody>
                                <tr>
                                        <td width="49%"><p style="padding-bottom:3px"><em>For help and more information:</em> </p><strong>Contact our 24 Hour Call Centre at 1800-209-5858, 1800-102-5858</strong></td>
                                        <td width="51%">&nbsp;</td>
                                </tr>
                                <tr>
                                        <td>Email: <a href="mailto:customercare@bajajallianz.co.in">customercare@bajajallianz.co.in</a> , Website <a href="http://www.bajajallianz.com">www.bajajallianz.com</a></td>
                                        <td style="text-align:right"><strong>Corporate Identification Number:  U66010PN2000PLC015329</strong></td>
                                </tr>
                                <tr>
                                        <td colspan="2"><strong>Demystify Insurance </strong><img src="image/wp.png" width="9" height="9" alt=""/> <a href="http://support.bajajallianz.com">http://support.bajajallianz.com</a> ; <img src="image/fb.png" width="8" height="9" alt=""/> <a href="https://www.facebook.com/BajajAllianz">https://www.facebook.com/BajajAllianz</a> ; <img src="image/twit.png" width="10" height="10" alt=""/> <a href="https://twitter.com/BajajAllianz">https://twitter.com/BajajAllianz</a> ; <img src="image/in.png" width="10" height="10" alt=""/> <a href="http://bit.do/bjazgi">http://bit.do/bjazgi</a></td>
                                        </tr>
                        </tbody>
                </table>

                        </div>';
            $mpdf->SetHTMLHeader($header);
            $mpdf->SetHTMLFooter($footer);

            $mpdf->SetProtection(array('print')); //cancel_certicate.png
            $mpdf->SetWatermarkImage('image/draft.png', 0.7, '', array(10, 0));
            $mpdf->showWatermarkImage = true;
            $mpdf->watermarkImgBehind = false;
            $mpdf->SetDisplayMode('fullpage');

            $mpdf->WriteHTML($html);
            $transitMovement = 'Container';
            if (!empty(Yii::$app->request->post('CargoForm'))) {
                $country = 'India';
                $transitMovement = $aCertificate['transit_type'];
                if ($aCertificate['transit_type'] == 'Import') {
                    $country = $aCertificate['origin_country'];
                } elseif ($aCertificate['transit_type'] == 'Export') {
                    $country = $aCertificate['destination_country'];
                }
            }
            if ($aCertificate['transit_type'] == 'Inland') {
                $country = 'India';
            }
            $fileName = $transitMovement . '_' . $country . '_' . $aCertificate['coverage_start_date'] . ".pdf";
            $mpdf->Output($fileName, 'I');
            //                $mpdf->Output();
            exit;
        }
    }

    public function actionSurveyDownload($id, $type = 'invoice')
    {
        $path = Yii::$app->params['uploadPath'] . "Offline/$id/";
        $objQuotation = \common\models\Quotation::find()->where(['id' => $id])->one();
        if ($objQuotation) {
            if ($type == 'invoice') {
                $path = $path . 'uploadInvoice/';
                $filename = $objQuotation->upload_invoice;
            } elseif ($type == 'offlne_format') {
                $path = $path . 'uploadOfflineFormat/';
                $filename = $objQuotation->upload_offline_format;
            } elseif ($type == 'survey_report') {
                $path = $path . 'uploadSurveyReport/';
                $filename = $objQuotation->survey_report;
            } elseif ($type == 'odc') {
                $path = $path . 'OdcDetails/';
                $filename = $objQuotation->uploaded_odc_details;
            } else {
                $path = $path . 'uploadPackageList/';
                $filename = $objQuotation->upload_packing_list;
            }
        }
        if (file_exists($path . "/" . $filename)) {
            Yii::$app->response->sendFile($path . "/" . $filename);
        } else {
            $type = strtolower($objQuotation->transit_type);
            Yii::$app->session->setFlash('error', 'File does not exist!');
            return $this->redirect(["/user/policy"]);
        }
    }

    public function actionBuyOdc($id)
    {
        $objSurvey = \common\models\InsuranceSurveyRequest::findOne([
            'quote_id' => $id,
            'status' => 1, 'user_id' => Yii::$app->user->identity->id, 'request_type' => 2
        ]);
        $accountbalance = new \common\models\UserAccountBalance();
        $balance = $accountbalance->getUserAccountBalance(Yii::$app->user->identity->id);
        if ($objSurvey) {
            $quoteObj = \common\models\Quotation::findOne(['id' => $id]);
            $quoteObj->setScenario('buy_survey');
            if ($quoteObj->is_odc == 1) {
                $newNetPremium = round($quoteObj->sum_insured * $objSurvey->user_rate / 100);
                if ($newNetPremium > $quoteObj->premium) {
                    $quoteObj->premium = $newNetPremium;
                    $aServiceTax = unserialize($quoteObj->service_tax_attributes);
                    if (empty($aServiceTax['igst'])) {
                        $newServiceTax = ($newNetPremium * $aServiceTax['sgst_rate'] / 100 +
                            $newNetPremium * $aServiceTax['cgst_rate'] / 100);
                    } else {
                        $newServiceTax = ($newNetPremium * $aServiceTax['igst_rate'] / 100);
                    }
                    $quoteObj->service_tax_amount = number_format($newServiceTax, 2, '.', '');
                    $quoteObj->total_premium = number_format(
                        round($newNetPremium + $newServiceTax + $quoteObj->stamp_duty_amount),
                        2,
                        '.',
                        ''
                    );
                } else {
                    $quoteObj->service_tax_amount = number_format($quoteObj->service_tax_amount, 2, '.', '');
                    $quoteObj->stamp_duty_amount = number_format($quoteObj->stamp_duty_amount, 2, '.', '');
                    $quoteObj->total_premium = number_format($quoteObj->total_premium, 2, '.', '');
                }
            } else {
                $newNetPremium = round($quoteObj->sum_insured * $objSurvey->user_rate / 100);
                if ($newNetPremium > $quoteObj->premium) {
                    $quoteObj->premium = $newNetPremium;
                    $aServiceTax = unserialize($quoteObj->service_tax_attributes);
                    if (empty($aServiceTax['igst'])) {
                        $newServiceTax = ($newNetPremium * $aServiceTax['sgst_rate'] / 100 +
                            $newNetPremium * $aServiceTax['cgst_rate'] / 100);
                    } else {
                        $newServiceTax = ($newNetPremium * $aServiceTax['igst_rate'] / 100);
                    }
                    $quoteObj->service_tax_amount = number_format($newServiceTax, 2);
                    $quoteObj->total_premium = number_format(
                        round($newNetPremium + $newServiceTax + $quoteObj->stamp_duty_amount),
                        2
                    );
                }
            }
            Yii::$app->session->set('user.quote_id', $quoteObj->id);
            if ($quoteObj->load(Yii::$app->request->post())) {
                $aRequest = Yii::$app->request->post('Quotation');
                $totalRemAmnt = str_replace(",", "", $aRequest['total_premium']);

                if (!$this->checkCompanyCredit($balance, $totalRemAmnt)) {
                    Yii::$app->session->setFlash('error', 'Payment is declined due to insufficient credit limit, please contact DgNote Administrator!');
                } else {
                    if ($quoteObj->validate()) {
                        $objState = \common\models\DgnoteTrailerState::find()
                            ->where(['name' => $quoteObj->billing_state])->one();
                        $objSez = $this->getSEZ();
                        $sezFlag = false;
                        if ($objSez->is_sez == 2 && $quoteObj->billing_detail == 2 && $quoteObj->is_sez == 1) {
                            $sezFlag = true;
                        } elseif ($objSez->is_sez == 1 && $quoteObj->billing_detail == 1 && $quoteObj->is_sez == 1) {
                            $sezFlag = true;
                        }
                        if ($sezFlag) {
                            $serailze =
                                \yii::$app->gst->getSeralizedGSTWithOutRound(
                                    $quoteObj->premium,
                                    $quoteObj->total_premium,
                                    $objState->state_code
                                );
                            $aUnSerailize = unserialize($serailze);
                            $aUnSerailize['igst'] = 0;
                            $aUnSerailize['sgst'] = 0;
                            $aUnSerailize['cgst'] = 0;
                            $serviceTax = serialize($aUnSerailize);
                        } else {
                            $serviceTax =
                                \yii::$app->gst->getSeralizedGSTWithOutRound(
                                    $quoteObj->premium,
                                    $quoteObj->total_premium,
                                    $objState->state_code
                                );
                        }
                        $quoteObj->service_tax_attributes = $serviceTax;
                        $quoteObj->total_premium = $totalRemAmnt;
                        $quoteObj->premium = $aRequest['premium'];
                        $quoteObj->service_tax_amount = $aRequest['service_tax_amount'];
                        $quoteObj->dgnote_commission = $objSurvey->dgnote_rate;
                        if ($quoteObj->save(false, [
                            'service_tax_attributes', 'total_premium', 'service_tax_amount', 'dgnote_commission', 'premium'
                        ])) {

                            return $this->redirect("odc-confirmation");
                        }
                    } else {
                        $aError = $quoteObj->getErrors();
                        foreach ($aError as $key => $value) {
                            Yii::$app->session->setFlash('error', $value[0]);
                            break;
                        }
                    }
                }
            }
            $aLabel = $this->getTransitModeLabel($quoteObj->transit_mode);
            $labelNo = $aLabel['labelNo'];
            $labelDt = $aLabel['labelDt'];
            $labelNm = $aLabel['labelNm'];
            $lableDtl = $aLabel['lableDtl'];

            return $this->render('buy_survey', [
                'balance' => $balance,
                'model' => $quoteObj,
                'cargotype' => strtolower($quoteObj->transit_type),
                'labelNo' => $labelNo,
                'labelDt' => $labelDt,
                'labelNm' => $labelNm,
                'lableDtl' => $lableDtl,
                'flagSez' => 0
            ]);
        } else {
            Yii::$app->session->setFlash('error', 'ODC can not be edited.');
            return $this->redirect(["/user/policy"]);
        }
    }


    public function actionOdcConfirmation()
    {
        $userId = \yii::$app->user->id;
        $companyId = \yii::$app->user->identity->company_id;
        $quoteId = Yii::$app->session->get('user.quote_id');
        $query = new \common\models\Quotation();
        $data = $query->getQuote($quoteId, $userId);

        $aContainer = [];
        $blanceModel = new \common\models\UserAccountBalance();
        $balance = $blanceModel->getUserAccountBalance($userId);
        //        echo $quoteId;die;
        $objQuote = \common\models\Quotation::findOne($quoteId);
        $remainingAmount = $objQuote->total_premium;
        $aStatus = Yii::$app->commonutils->checkMasterPolicyAndCoverageDate($objQuote);
        if ($aStatus['status'] == 'failed') {
            Yii::$app->session->setFlash('error', $aStatus['error']);
            return $this->redirect(['user/policy']);
        }
        if (Yii::$app->request->post()) {
            $aRequest = Yii::$app->request->post();
            $quoteId = $aRequest['quote_id'];

            // lock amount..
            //            $blanceModel->updateLockBalance($objQuote->user_id, $objQuote->total_premium, 1);
            $objSurveyObj = \common\models\InsuranceSurveyRequest::findOne(
                ['quote_id' => $quoteId, 'request_type' => 2]
            );
            if ($query->generateODCCertificate($quoteId, $objSurveyObj)) {
                $blanceModel->updateAccountBalanceWithCompanyCredit(
                    $companyId,
                    $objQuote->total_premium,
                    1
                );

                $objSurveyObj->status = 3;
                $objSurveyObj->save(false, ['status']);
                \Yii::$app->session->remove('user.quote_id');
            }
            return $this->redirect(['user/policy']);
        }

        $objCredit = \common\models\TransportCredit::findOne([
            'company_id'
            => \yii::$app->user->identity->company_id, 'product_id' => 1, 'status' => 'Active'
        ]);
        $credit = false;
        if ($objCredit && $this->checkCompanyCredit($balance, $remainingAmount)) {
            $credit = true;
        }

        return $this->render('odc_confirmation', [
            'data' => $data,
            'userbalance' => $balance,
            'aContainer' => $aContainer,
            'balance' => $balance,
            'product' => 2,
            'remainingAmount' => $remainingAmount,
            'cargotype' => 'import',
            'objCredit' => $objCredit,
            'credit' => $credit
        ]);
    }


    /**
     * Cance Draft Certificate..
     * @param type $id
     * @return type
     */
    public function actionCancelDraft($id)
    {
        $objQuote = \common\models\Quotation::find()
            ->where(['id' => $id, 'is_draft' => 1])->one();
        if ($objQuote) {
            $objQuote->is_draft = 2;
            $objQuote->status = 0;
            $objQuote->modified_at = date('Y-m-d H:i:s');
            //            $objQuote->modified_by = Yii::$app->user->identity->id;
            if ($objQuote->save(false, ['is_draft', 'status', 'modified_at'])) {
                Yii::$app->getSession()->addFlash(
                    'success',
                    'Your draft policy has been cancelled successfully.'
                );
                return $this->redirect(['user/policy']);
            }
        } else {
            Yii::$app->getSession()->addFlash(
                'error',
                'Invalid Request.'
            );
            return $this->redirect(['user/policy']);
        }
    }

    /**
     * Edit Draft for Cargo Only..
     * @param \frontend\insurance\controllers\type $type
     * @param \frontend\insurance\controllers\type $id
     * @param \frontend\insurance\controllers\type $isBuy
     * @return \frontend\insurance\controllers\type
     * @param type $type
     * @param type $id
     * @param type $isBuy
     * @return type
     */
    public function actionEditDraft($type, $id, $isBuy = false)
    {
        $objQuote = \common\models\Quotation::find()
            ->where([
                'transit_type' => $type, 'id' => $id,
                'insurance_product_type_id' => 2, 'is_draft' => 1
            ])
            ->one();
        $coverageDate = strtotime($objQuote->coverage_start_date);
        $currentDate = strtotime(date('Y-m-d'));
        if ($objQuote) {
            $accountbalance = new \common\models\UserAccountBalance();
            $balance = $accountbalance->getUserAccountBalance(Yii::$app->user->identity->id);
            $objSez = $this->getSEZ();
            $cargoModel = new CargoForm();
            $cargoModel->setCargoForDraft($id);
            $objCargoCertificate = \common\models\CompanyPolicyCargoCertificate::checkBajajGroupExitOrNotWithInfo(Yii::$app->user->identity->company_id);
            $backDate = $cargoModel->getBackAllowDate(
                $objCargoCertificate,
                Yii::$app->params['allowBackDays']
            );
            if ($cargoModel->load(Yii::$app->request->post())) {

                $aRequest = Yii::$app->request->post();
                if ($aRequest['CargoForm']['is_offline'] == 1 && $aRequest['CargoForm']['transit_type'] == 'Inland') {
                    $cargoModel->cr_2_offline = 1;
                }

                if ($aRequest['CargoForm']['back_date'] == 1) {
                    $cargoModel->is_offline = 1;
                }

                $isDraft = isset($aRequest['Draft']) ? 1 : 0;

                $currentD = date('Y-m-d');
                $ct = date('Y-m-d', strtotime($aRequest['CargoForm']['coverage_start_date']));
                if ($currentD > $ct) {
                    $cargoModel->is_offline = 1;
                    $cargoModel->back_date = 1;
                }

                if (!$this->checkCompanyCredit($balance, $aRequest['CargoForm']['total_premium'])) {
                    Yii::$app->session->setFlash('error', 'Payment is declined due to insufficient credit limit, please contact DgNote Administrator!');
                } else {
                    $cuntryflag = true;
                    if (isset($aRequest['CargoForm']['country_offline']) && $aRequest['CargoForm']['country_offline'] == 1) {
                        if ($aRequest['CargoForm']['transit_type'] == 'Import') {
                            $country = $aRequest['CargoForm']['origin_country'];
                        } else {
                            $country = $aRequest['CargoForm']['destination_country'];
                        }
                        if (!\common\models\Country::checkSanctionAndHRACountryByName($country)) {
                            $cuntryflag = false;
                            Yii::$app->session->setFlash('error', 'There is some issue to selected country.Please select again!');
                        }
                    }
                    if ($cuntryflag) {
                        if ($isBuy) {
                            if ($currentD > $ct) {
                                $objQuote->is_offline = 1;
                                $objQuote->back_date = 1;
                            }
                            $objQuote->receipt_no = $aRequest['CargoForm']['receipt_no'];
                            $objQuote->receipt_date = strtotime($aRequest['CargoForm']['receipt_date']) > 0 ?
                                date('Y-m-d', strtotime($aRequest['CargoForm']['receipt_date'])) : '';
                            $objQuote->authority_name = $aRequest['CargoForm']['authority_name'];
                            $objQuote->authority_detail = $aRequest['CargoForm']['authority_detail'];
                            $objQuote->buyer_details = $aRequest['CargoForm']['buyer_details'];
                            $objQuote->seller_details = $aRequest['CargoForm']['seller_details'];
                            $objQuote->reference_no = $aRequest['CargoForm']['reference_no'];
                            $objQuote->additional_details = $aRequest['CargoForm']['additional_details'];
                            if ($objQuote->save(false, [
                                'receipt_no', 'receipt_date', 'authority_name', 'authority_detail', 'buyer_details', 'seller_details', 'reference_no', 'additional_details', 'is_offline', 'back_date'
                            ])) {
                                Yii::$app->session->set('user.quote_id', $objQuote->id);
                                return $this->redirect("confirmation");
                            } else {
                                Yii::$app->session->setFlash('error', 'There is some error please try again.');
                                return $this->redirect("edit-draft?type=$type&id=$id");
                            }
                        } else {
                            $checSumInsured = $aRequest['CargoForm']['invoice_amount'];
                            unset($aRequest['CargoForm']['comma_sum_insured']);
                            $this->saveUserContactDetails(
                                $aRequest['CargoForm']['institution_name'],
                                $aRequest['CargoForm']['address'],
                                $aRequest['CargoForm']['city'],
                                $aRequest['CargoForm']['state'],
                                $aRequest['CargoForm']['pincode'],
                                $aRequest['CargoForm']['gstin'],
                                $aRequest['CargoForm']['party_name'],
                                $aRequest['CargoForm']['billing_city'],
                                $aRequest['CargoForm']['billing_state'],
                                $aRequest['CargoForm']['billing_address'],
                                $aRequest['CargoForm']['billing_pincode']
                            );
                            $productModel = new \common\models\InsuranceProduct();
                            $transitTypeModel = new \common\models\TransitType();
                            $transitTypeId = $transitTypeModel->getIdByTransitType($cargoModel->transit_type);
                            $transitModeModel = new \common\models\TransitMode();
                            $transitModeId = $transitModeModel->getIdByTransitMode($cargoModel->transit_mode);
                            $aInsuranceProduct = $productModel->getProductCodeByMatrix(
                                CargoForm::NON_CONTAINER_PRODUCT_ID,
                                $transitTypeId,
                                $transitModeId
                            );

                            $cargoModel->origin_country = $aRequest['CargoForm']['origin_country'];
                            $cargoModel->destination_country = $aRequest['CargoForm']['destination_country'];
                            $cargoModel->branch = Yii::$app->user->identity->branch;
                            $cargoModel->company_id = Yii::$app->user->identity->company_id;
                            $cargoModel->product_code = $aInsuranceProduct['code'];

                            $cargoModel->valuation_basis = ($aRequest['CargoForm']['valuation_basis'] == 'Terms Of Sale') ? 'TOS' : $aRequest['CargoForm']['valuation_basis'];
                            $cargoModel->contact_name = Yii::$app->user->identity->first_name . " " . Yii::$app->user->identity->last_name;
                            $cargoModel->mobile = Yii::$app->user->identity->mobile;
                            $cargoModel->country = Yii::$app->user->identity->country;
                            $cargoModel->total_premium = $this->removeCommaFromAmount($aRequest['CargoForm']['total_premium']);

                            $cargoModel->premium = $this->removeCommaFromAmount($aRequest['CargoForm']['premium']);
                            $cargoModel->gstin = $aRequest['CargoForm']['gstin'];
                            $cargoModel->pan = \yii::$app->gst->getPanFromGSTNo($cargoModel->gstin);
                            $cargoModel->pincode = $aRequest['CargoForm']['pincode'];
                            $cargoModel->w2w = isset($aRequest['CargoForm']['w2w']) ? $aRequest['CargoForm']['w2w'] : 0;
                            $cargoModel->user_detail = (isset($aRequest['user_detail']) && $aRequest['user_detail'] == 'on') ? 1 : 0;
                            $cargoModel->billing_detail = isset($aRequest['billing_detail'][0]) ? $aRequest['billing_detail'][0] : 0;
                            $cargoModel->is_sez = isset($aRequest['CargoForm']['is_sez']) ? $aRequest['CargoForm']['is_sez'] : 0;
                            $cargoModel->country_offline = $aRequest['CargoForm']['country_offline'];
                            $cargoModel->cr_2_offline = $aRequest['CargoForm']['cr_2_offline'];
                            $cargoModel->country_type = isset($aRequest['CargoForm']['country_type']) ?
                                $aRequest['CargoForm']['country_type'] : '';
                            $sezFlag = false;
                            $cargoModel->service_tax_amount = $this->removeCommaFromAmount($aRequest['CargoForm']['service_tax_amount']);
                            if ($objSez->is_sez == 2 && $cargoModel->billing_detail == 2 && $cargoModel->is_sez == 1) {
                                $cargoModel->service_tax_amount = 0;
                                $sezFlag = true;
                            } elseif ($objSez->is_sez == 1 && $cargoModel->billing_detail == 1 && $cargoModel->is_sez == 1) {
                                $cargoModel->service_tax_amount = 0;
                                $sezFlag = true;
                            }

                            $commodityModel = new \common\models\Commodity();

                            $commodityId = $commodityModel->getIdByCommodity($aRequest['CargoForm']['commodity']);
                            $companyId = Yii::$app->user->identity->company_id;
                            if (\common\models\CompanyPolicyCargoCertificate::checkBajajGroupExitOrNot($companyId)) {
                                $objGroup = \common\models\CompanyPolicyCargoCertificate::checkBajajRateGroupForPolicy($commodityId, $companyId);
                                if (!$objGroup) {
                                    // send mail for admin that policy is not mapped for that company
                                    Yii::$app->session->setFlash('error', 'Commodity not configure please contact DgNote Administrator!');
                                    return $this->redirect("edit-draft?type=$type&id=$id");
                                } else {
                                    $cargoModel->bajaj_group = $objGroup->masterPolicy->bajaj_group;
                                }
                            }

                            $cmnRtMdl = $this->isCertificate(Yii::$app->user->identity->company_id, 2, $commodityId);
                            if ($cmnRtMdl) {
                                $cargoModel->dgnote_commission = $this->getDgnoteRate($commodityId, $cargoModel->w2w);
                                if ($aRequest['CargoForm']['transit_type'] == 'Export') {
                                    if (!empty($aRequest['CargoForm']['surveyor_city']) && $aRequest['CargoForm']['surveyor_city'] == 'NA') {
                                        $cargoModel->surveyor_country = $cargoModel->destination_country;
                                        $cargoModel->surveyor_address = $aRequest['CargoForm']['surveyor_address'];
                                        $cargoModel->surveyor_agent = $aRequest['CargoForm']['surveyor_agent'];
                                    } else {
                                        $survAgentResutl = $this->getSurveyoragent(
                                            $aRequest,
                                            $commodityId,
                                            $cargoModel->destination_country,
                                            $transitTypeId,
                                            $aRequest['CargoForm']['terms_of_sale']
                                        );
                                        $cargoModel->surveyor_address = $survAgentResutl['surveyor_address'];
                                        $cargoModel->surveyor_id = $survAgentResutl['surveyor_id'];
                                        $cargoModel->surveyor_agent = $survAgentResutl['surveyor_agent'];
                                        $cargoModel->surveyor_city = $survAgentResutl['surveyor_city'];
                                    }
                                } else {
                                    $cargoModel->surveyor_country = $cargoModel->destination_country;
                                    $cargoModel->surveyor_address = $aRequest['CargoForm']['surveyor_address'];
                                    $cargoModel->surveyor_agent = $aRequest['CargoForm']['surveyor_agent'];
                                }
                                $upload_invoice = $upload_packing_list = $upload_offline_format = '';
                                if ($aRequest['CargoForm']['is_offline'] != 0 || (isset($aRequest['CargoForm']['is_odc']) &&
                                    $aRequest['CargoForm']['is_odc'] != 0)) {
                                    $upload_invoice = \yii\web\UploadedFile::getInstance($cargoModel, 'upload_invoice');
                                    $upload_packing_list = \yii\web\UploadedFile::getInstance($cargoModel, 'upload_packing_list');
                                    $upload_offline_format = \yii\web\UploadedFile::getInstance($cargoModel, 'upload_offline_format');
                                }
                                /*$cargoModel->upload_invoice = $upload_invoice!=''?$upload_invoice->name: $objQuote->upload_invoice;
                                $cargoModel->upload_packing_list = $upload_packing_list!=''?$upload_packing_list->name:$objQuote->upload_packing_list;
                                $cargoModel->upload_offline_format = $upload_offline_format!=''? $upload_offline_format->name:$objQuote->upload_offline_format;
                                */
                                $survey_report = '';
                                if (isset($aRequest['CargoForm']['survey_report']) && count($aRequest['CargoForm']['survey_report']) > 0) {
                                    $survey_report = \yii\web\UploadedFile::getInstances($cargoModel, 'survey_report');
                                    $totFileSizeOdc = 0;
                                    if (count($survey_report) > 0) {
                                        foreach ($survey_report as $odc) {
                                            $totFileSizeOdc += (isset($odc->size) ? $odc->size : 0);
                                        }
                                    }
                                    $fileSizeMbOdc = $totFileSizeOdc / (1024 * 1024);
                                    if ($fileSizeMbOdc > '5') {
                                        Yii::$app->session->setFlash('error', 'Uploaded Survey Details file size can not be greater than 5MB.');
                                        return $this->redirect("edit-draft?type=$type&id=$id");
                                    }
                                }

                                // change invoice name as requirement 
                                $upload_invoice_actual_name = '';
                                if ($upload_invoice) {
                                    $upload_invoice_image_arr = explode('.', $upload_invoice->name);
                                    $inv_no = $aRequest['CargoForm']['invoice_no'];
                                    $upload_invoice_actual_name = $inv_no . '_Invoice.' . end($upload_invoice_image_arr);
                                }

                                $cargoModel->upload_invoice = $upload_invoice != '' ? $upload_invoice_actual_name : "";

                                // change invoice name as requirement
                                $upload_packing_list_actual_name = '';
                                if ($upload_packing_list) {
                                    $upload_packing_list_actual_name_arr = explode('.', $upload_packing_list->name);
                                    $inv_no = $aRequest['CargoForm']['invoice_no'];
                                    $upload_packing_list_actual_name = $inv_no . '_Packing List.' . end($upload_packing_list_actual_name_arr);
                                }
                                $cargoModel->upload_packing_list = $upload_packing_list != '' ? $upload_packing_list_actual_name : "";


                                // change invoice name as requirement
                                $upload_offline_format_actual_name = '';
                                if ($upload_offline_format) {
                                    $upload_offline_format_actual_name_arr = explode('.', $upload_offline_format->name);
                                    $inv_no = $aRequest['CargoForm']['invoice_no'];
                                    $upload_offline_format_actual_name = $inv_no . '_Offline Bajaj Format.' . end($upload_offline_format_actual_name_arr);
                                }
                                $cargoModel->upload_offline_format = $upload_offline_format != '' ? $upload_offline_format_actual_name : "";




                                if (isset($aRequest['CargoForm']['hidden_upload_invoice']) && $cargoModel->upload_invoice == "") {
                                    $cargoModel->upload_invoice = $aRequest['CargoForm']['hidden_upload_invoice'];
                                }
                                if (isset($aRequest['CargoForm']['hidden_upload_packing_list']) && $cargoModel->upload_packing_list == "") {
                                    $cargoModel->upload_packing_list = $aRequest['CargoForm']['hidden_upload_packing_list'];
                                }
                                if (isset($aRequest['CargoForm']['hidden_upload_survey_report']) && $cargoModel->survey_report == "") {
                                    $cargoModel->survey_report = $aRequest['CargoForm']['hidden_upload_survey_report'];
                                }

                                if ($cargoModel->validate()) {
                                    $validationObj = new \frontend\insurance\components\MasterDataValidatonComponent();
                                    $aError = $validationObj->checkServerValidation($cargoModel, 'noncontainer', $cmnRtMdl);
                                    $objState = \common\models\DgnoteTrailerState::find()
                                        ->where(['name' => $cargoModel->billing_state])->one();
                                    if ($aError['status']) {
                                        if ($sezFlag) {
                                            $serailze =
                                                \yii::$app->gst->getSeralizedGSTWithOutRound(
                                                    $cargoModel->premium,
                                                    $cargoModel->total_premium,
                                                    $cargoModel->billing_state
                                                );
                                            $aUnSerailize = unserialize($serailze);
                                            $aUnSerailize['igst'] = 0;
                                            $aUnSerailize['sgst'] = 0;
                                            $aUnSerailize['cgst'] = 0;
                                            $cargoModel->service_tax_attributes = serialize($aUnSerailize);
                                        } else {
                                            $cargoModel->service_tax_attributes =
                                                \yii::$app->gst->getSeralizedGSTWithOutRound(
                                                    $cargoModel->premium,
                                                    $cargoModel->total_premium,
                                                    $cargoModel->billing_state
                                                );
                                        }
                                        $cargoModel->is_draft = $isDraft;
                                        if ($quote = $cargoModel->save($upload_invoice, $upload_packing_list, $upload_offline_format)) {
                                            Yii::$app->session->set('user.quote_id', $quote->id);
                                            if (count($aRequest['CargoForm']['uploaded_odc_details']) > 0) {
                                                $uploaded_odc_details = \yii\web\UploadedFile::getInstances($cargoModel, 'uploaded_odc_details');
                                                $totFileSizeOdc = 0;
                                                if (count($uploaded_odc_details) > 0) {
                                                    foreach ($uploaded_odc_details as $odc) {
                                                        $totFileSizeOdc += (isset($odc->size) ? $odc->size : 0);
                                                    }
                                                }
                                                $fileSizeMbOdc = $totFileSizeOdc / (1024 * 1024);
                                                if ($fileSizeMbOdc > '7.5') {
                                                    Yii::$app->session->setFlash('error', 'Uploaded ODC Details file size can not be greater than 7MB.');
                                                    return $this->redirect("edit-draft?type=$type&id=$id");
                                                }

                                                if ($uploaded_odc_details) {
                                                    //upload odc images
                                                    $imagePdfPath = $cargoModel->saveOdcDetailsAsPdf($uploaded_odc_details, $quote);
                                                    $quoteOdc = \common\models\Quotation::findOne($quote->id);
                                                    $quoteOdc->uploaded_odc_details = $imagePdfPath;
                                                    $quoteOdc->save([false, 'uploaded_odc_details']);
                                                }
                                                if ($survey_report) {
                                                    //upload survey report images
                                                    $imagePdfPath = $cargoModel->saveSurveyReportAsPdf($survey_report, $quote);
                                                    $quoteOdc = \common\models\Quotation::findOne($quote->id);
                                                    $quoteOdc->survey_report = $imagePdfPath;
                                                    $quoteOdc->is_offline = 1;
                                                    $quoteOdc->is_survey = 1;
                                                    $quoteOdc->save([false, 'survey_report', 'is_offline', 'is_survey']);
                                                }
                                            }
                                            if ($isDraft) {
                                                Yii::$app->session->setFlash('success', 'Your policy has been drafted successfully.');
                                                return $this->redirect(['user/policy']);
                                            }
                                            return $this->redirect("confirmation");
                                        } else {
                                            Yii::$app->session->setFlash('error', 'There is some issue.Please contact with DgNote Administrator.');
                                            return $this->redirect("edit-draft?type=$type&id=$id");
                                        }
                                    } else {
                                        Yii::$app->session->setFlash('error', $aError['error']);
                                        return $this->redirect("edit-draft?type=$type&id=$id");
                                    }
                                } else {
                                    $aError = $cargoModel->getErrors();
                                    foreach ($aError as $key => $value) {
                                        Yii::$app->session->setFlash('error', $value[0]);
                                        break;
                                    }
                                }
                            } else {
                                Yii::$app->session->setFlash('error', 'Commodity Rates are not configure, please contact DgNote Administrator!');
                            }
                        }
                    }
                }
            }
            $view = $type . '_draft';
            if ($isBuy) {
                $view = 'buy_draft_' . $type;
            }
            //            $backDate = Yii::$app->params['allowBackDays'];
            return $this->render($view, [
                'model' => $cargoModel,
                'balance' => $balance,
                'cargotype' => $type,
                'id' => $id,
                'backDate' => $backDate,
                'flagSez' => '',
                'objSez' => $objSez,
                'isBuy' => $isBuy,
                'objQuote' => $objQuote
            ]);
        } else {
            Yii::$app->getSession()->addFlash(
                'error',
                'Invalid Request.'
            );
            return $this->redirect(['user/policy']);
        }
    }

    /**
     * Edit Draft for container only..
     * @param type $type
     * @param type $id
     * @param type $isBuy
     * @return type
     */
    public function actionEditDraftContainer($type, $id, $isBuy = false)
    {
        $objQuote = \common\models\Quotation::find()
            ->where(['id' => $id, 'insurance_product_type_id' => 1])->one();

        if ($objQuote) {
            $accountbalance = new \common\models\UserAccountBalance();
            $balance = $accountbalance->getUserAccountBalance(Yii::$app->user->identity->id);
            $objSez = $this->getSEZ();
            $contQuotModel = new ContainerForm();
            $contQuotModel->setContainerForDraft($id);
            $contQuotModel->company_id = \Yii::$app->user->identity->company_id;
            $container = new \common\models\InsuranceContainer();
            $existingModel = \common\models\InsuranceContainer::find()->where(['quote_id' => $id])->all();
            $cmnRtMdl = $this->isCertificate(Yii::$app->user->identity->company_id, 1, '');
            $uploadModel = new \frontend\insurance\models\UploadFile();
            $backDate = $contQuotModel->getBackAllowDate(Yii::$app->params['allowBackDays']);
            if (
                $contQuotModel->load(Yii::$app->request->post()) &&
                $container->load(Yii::$app->request->post())
            ) {
                $aRequest = Yii::$app->request->post();

                $isDraft = isset($aRequest['Draft']) ? 1 : 0;
                $commodityModel = new \common\models\Commodity();
                $commodityId = $commodityModel->getIdByCommodity($objQuote->commodity);
                $transitTypeModel = new \common\models\TransitType();
                $transitTypeId = $transitTypeModel->getIdByTransitType(ContainerForm::TRANSIT_TYPE);
                $transitModeModel = new \common\models\TransitMode();
                $transitModeId = $transitModeModel->getIdByTransitMode($contQuotModel->transit_mode);
                $objPackaging = new \common\models\Packaging();
                $data = $objPackaging
                    ->getPackaging($commodityId, $transitTypeId, $transitModeId);
                if (!$this->checkCompanyCredit($balance, $aRequest['ContainerForm']['total_premium'])) {
                    Yii::$app->session->setFlash('error', 'Payment is declined due to insufficient credit limit, please contact DgNote Administrator!');
                } elseif (!isset($data[0]['code'])) {
                    Yii::$app->session->setFlash('error', 'Issue related to packaging, please contact DgNote Administrator!');
                } else {
                    $checSumInsured = $aRequest['ContainerForm']['sum_insured'];
                    $amount = \Yii::$app->params['maxInsurancePremiumShrtMsg'];
                    if ($checSumInsured > \Yii::$app->params['maxInsurancePremium']) {
                        Yii::$app->session->setFlash('error', "Sum insured should not be greater than "
                            . "Rs. $amount Crores. Please contact DgNote Team at contact@dgnote.com "
                            . "or +91-22-22652123 to buy offline policy.");
                        return $this->redirect("container");
                    }
                    if ($isBuy) {
                        $objQuote->buyer_details = $aRequest['ContainerForm']['buyer_details'];
                        $objQuote->seller_details = $aRequest['ContainerForm']['seller_details'];
                        $objQuote->reference_no = $aRequest['ContainerForm']['reference_no'];
                        $objQuote->additional_details = $aRequest['ContainerForm']['additional_details'];

                        if ($objQuote->save(false, [
                            'buyer_details', 'seller_details', 'reference_no', 'additional_details'
                        ])) {
                            Yii::$app->session->set('user.quote_id', $objQuote->id);
                            return $this->redirect("confirmation");
                        } else {
                            Yii::$app->session->setFlash('error', 'There is some error please try again.');
                            return $this->redirect(["edit-draft-container?type=$type&id=$id&isBuy=true"]);
                        }
                    } else {
                        $this->saveUserContactDetails(
                            $aRequest['ContainerForm']['institution_name'],
                            $aRequest['ContainerForm']['address'],
                            $aRequest['ContainerForm']['city'],
                            $aRequest['ContainerForm']['state'],
                            $aRequest['ContainerForm']['pincode'],
                            $aRequest['ContainerForm']['gstin'],
                            $aRequest['ContainerForm']['party_name'],
                            $aRequest['ContainerForm']['billing_city'],
                            $aRequest['ContainerForm']['billing_state'],
                            $aRequest['ContainerForm']['billing_address'],
                            $aRequest['ContainerForm']['billing_pincode']
                        );
                        $contQuotModel->branch = Yii::$app->user->identity->branch;
                        $contQuotModel->contact_name = Yii::$app->user->identity->first_name . "" . Yii::$app->user->identity->last_name;
                        $contQuotModel->mobile = Yii::$app->user->identity->mobile;
                        $contQuotModel->company_id = Yii::$app->user->identity->company_id;
                        $contQuotModel->country = Yii::$app->user->identity->country;
                        $contQuotModel->container_movement = ContainerForm::CONT_MOVE_SINGLE;
                        $contQuotModel->total_premium = $this->removeCommaFromAmount($aRequest['ContainerForm']['total_premium']);


                        $contQuotModel->premium = !empty($aRequest['ContainerForm']['premium']) ? $this->removeCommaFromAmount($aRequest['ContainerForm']['premium']) : '';
                        $contQuotModel->gstin = $aRequest['ContainerForm']['gstin'];
                        $contQuotModel->pan = \yii::$app->gst->getPanFromGSTNo($contQuotModel->gstin);
                        $contQuotModel->pincode = $aRequest['ContainerForm']['pincode'];
                        $contQuotModel->user_detail = (isset($aRequest['user_detail']) && $aRequest['user_detail'] == 'on') ? 1 : 0;
                        $contQuotModel->billing_detail = isset($aRequest['billing_detail'][0]) ? $aRequest['billing_detail'][0] : 0;
                        $contQuotModel->is_sez = isset($aRequest['ContainerForm']['is_sez']) ? $aRequest['ContainerForm']['is_sez'] : 0;
                        $contQuotModel->packing = isset($data[0]['code']) ? $data[0]['code'] : '';

                        $sezFlag = false;
                        $contQuotModel->service_tax_amount = $this->removeCommaFromAmount($aRequest['ContainerForm']['service_tax_amount']);
                        if (
                            $objSez->is_sez == 2 && $contQuotModel->billing_detail == 2 &&
                            $contQuotModel->is_sez == 1
                        ) {
                            $contQuotModel->service_tax_amount = 0;
                            $sezFlag = true;
                        } elseif (
                            $objSez->is_sez == 1 && $contQuotModel->billing_detail == 1 &&
                            $contQuotModel->is_sez == 1
                        ) {
                            $contQuotModel->service_tax_amount = 0;
                            $sezFlag = true;
                        }
                        $commodityModel = new \common\models\Commodity();

                        if ($contQuotModel->validate($contQuotModel->getAttributes())) {
                            $contQuotModel->dgnote_commission = $this->getDgnoteRate($commodityModel->getIdByCommodity($aRequest['ContainerForm']['commodity']));
                            $validationObj = new \frontend\insurance\components\MasterDataValidatonComponent();
                            $aError = $validationObj->checkServerValidation($contQuotModel, 'container', $cmnRtMdl);
                            if ($aError['status']) {
                                if ($contQuotModel->validate()) {
                                    $objState = \common\models\DgnoteTrailerState::find()
                                        ->where(['name' => $contQuotModel->billing_state])->one();
                                    if ($sezFlag) {
                                        $serailze =
                                            \yii::$app->gst->getSeralizedGSTWithOutRound(
                                                $contQuotModel->premium,
                                                $contQuotModel->total_premium,
                                                $contQuotModel->billing_state
                                            );
                                        $aUnSerailize = unserialize($serailze);
                                        $aUnSerailize['igst'] = 0;
                                        $aUnSerailize['sgst'] = 0;
                                        $aUnSerailize['cgst'] = 0;
                                        $contQuotModel->service_tax_attributes = serialize($aUnSerailize);
                                    } else {
                                        $contQuotModel->service_tax_attributes =
                                            \yii::$app->gst->getSeralizedGSTWithOutRound(
                                                $contQuotModel->premium,
                                                $contQuotModel->total_premium,
                                                $contQuotModel->billing_state
                                            );
                                    }
                                    $contQuotModel->is_draft = $isDraft;

                                    if ($quote = $contQuotModel->save()) {
                                        $aErrr = $container->addForDraft($quote->id);
                                        if ($isDraft) {
                                            Yii::$app->session->setFlash('success', 'Your policy has been drafted successfully.');
                                            return $this->redirect(['user/policy']);
                                        }
                                        if (count($aErrr) == 0) {
                                            Yii::$app->session->set('user.quote_id', $quote->id);
                                            $this->redirect("confirmation");
                                        } else {
                                            Yii::$app->session->setFlash('error', $aErrr['err']);
                                            $transaction->rollBack();
                                            return $this->redirect(["edit-draft-container?type=$type&id=$id"]);
                                        }
                                    }
                                } else {
                                    $error = $contQuotModel->getErrors();
                                    if ($error) {
                                        foreach ($error as $key => $value) {
                                            Yii::$app->session->setFlash('error', $error[$key][0]);
                                        }
                                    }
                                }
                            } else {
                                Yii::$app->session->setFlash('error', $aError['error']);
                                return $this->redirect(["edit-draft-container?type=$type&id=$id"]);
                            }
                        } else {
                            Yii::$app->session->setFlash('error', $contQuotModel->getErrors());
                            return $this->redirect(["edit-draft-container?type=$type&id=$id"]);
                        }
                    }
                }
            }
            $objUser = $contQuotModel->getUserFlag(Yii::$app->user->identity->company_id);
            //            $backDate = Yii::$app->params['allowBackDays'];
            $view = 'container_draft';
            if ($isBuy) {
                $view = 'buy_draft_container';
            }
            return $this->render($view, [
                'model' => $contQuotModel,
                'container' => $container,
                'balance' => $balance,
                'uploadModel' => $uploadModel,
                'cargotype' => '',
                'companyRt' => $cmnRtMdl,
                'objUser' => $objUser,
                'backDate' => $backDate,
                'objSez' => $objSez,
                'isBuy' => $isBuy,
                'id' => $id,
                'existingModel' => $existingModel,
                'objQuote' => $objQuote
            ]);
        } else {
            echo '<pre>';
            print_r($objQuote);
            die;
            Yii::$app->getSession()->addFlash(
                'error',
                'Invalid Request11.'
            );
            return $this->redirect(['user/policy']);
        }
    }



    public function actionDraftCertificate($quoteId)
    {
        $this->layout = false;
        $aQuote =  \common\models\Quotation::find()->joinWith(['transaction'])->where(['mi_quote.id' => $quoteId])->one();
        $mdlCom =  \common\models\Commodity::find()->where(['code' => $aQuote->commodity])->one();
        if ($aQuote->insurance_product_type_id == 2) {
            $certificateMdl = new \common\models\CompanyPolicyNo();
            $certificateObj  = $certificateMdl->checkCompanyForDetail(\yii::$app->user->identity->company_id, $mdlCom->id);
        } else {
            $certificateObj = \common\models\CompanyPolicyNo::find()->where(['container' => 1, 'status' => 1])->one();
        }



        $acontainer =  \common\models\InsuranceContainer::find()->where(['quote_id' => $quoteId])->all();
        $stringPremium = \Yii::$app->numbrString->convertNumberToSting($aQuote['total_premium']);
        $countryFlag = false;
        $cntryCat = '';
        if (($aQuote['transit_type'] == 'Import' || $aQuote['transit_type'] == 'Export')) {
            if ($aQuote['transit_type'] == 'Import') {
                $country = $aQuote['origin_country'];
            } else {
                $country = $aQuote['destination_country'];
            }
            $cntryMdl = $cntryMdl = \common\models\Country::find()->where(['name' => trim($country)])->one();
            if ($cntryMdl && ($cntryMdl->country_category == 'S' || $cntryMdl->country_category == 'H')) {
                $countryFlag = true;
            }
            $cntryCat = isset($cntryMdl->country_category) ? $cntryMdl->country_category : '';
        }
        if ($aQuote->insurance_product_type_id == 2) {
            $aClauses = \Yii::$app->numbrString->getClauses(
                $aQuote['transit_type'],
                $aQuote['transit_mode'],
                $aQuote['commodity'],
                $aQuote['w2w'],
                $aQuote['coverage'],
                $cntryCat,
                $aQuote['is_odc'],
                $aQuote['odc_barge_shipement'],
                $aQuote['created_at']
            );
            $html = $this->renderPartial('cargo_certificate_draft', [
                'aQuote' => $aQuote,
                'aContainer' => $acontainer, 'stringPremium' => $stringPremium, 'commodity_name' => $mdlCom->name, 'stopPolicyDt' => '', 'aClauses' => $aClauses, 'countryFlag' => $countryFlag, 'masterObj' => ''
            ]);
        } else {
            $html = $this->renderPartial('container_certificate_draft', [
                'aQuote' => $aQuote,
                'aContainer' => $acontainer, 'stringPremium' => $stringPremium, 'commodity_name' => $mdlCom->name, 'stopPolicyDt' => '', 'countryFlag' => $countryFlag
            ]);
        }
        // $mpdf = new \mPDF('c', 'A4', '', '', 10, 10, 35, 25, 5, 5);
        $mpdf = new Mpdf([
            'mode' => 'c',
            'format' => 'A4',
            'margin_top' => 35,
            'margin_bottom' => 25,
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_header' => 5,
            'margin_footer' => 5,
            'tempDir' => __DIR__ . '/custom/temp/dir/path'
        ]);
        $header = '<div class="logo"><img src="image/bajaj_logo.png" width="120" height="30" alt=""/></div>
                    <div class="center" style="margin-left:11px;margin-right:11px;">
                            <span style="font-family: Calibri;font-size: 12px;    font-style: normal;font-variant: normal;line-height: 14pt;padding: 0;margin: 0;margin-bottom: 5px;">BAJAJ ALLIANZ GENERAL INSURANCE COMPANY LIMITED</span>
                            <h4>(A Company incorporated under Indian Companies Act, 1956 and licensed by Insurance Regulatory and Development Authority of India [IRDAI] vide Regd. No.113) : <br>
                                    Regd. Office: GE Plaza, Airport Road, Yerwada, Pune – 411006 (India)</h4>
                            <h3>    Marine Insurance Certificate <span>UIN.IRDAN113P0022V01200102</span></h3>
                    </div>';

        $footer = '<div class="footer"  style="margin-left:11px;margin-right:11px;">
                            <table class="tblData2" width="100%" border="0" cellspacing="0" cellpadding="0">
                    <tbody>
                            <tr>
                                    <td width="49%"><p style="padding-bottom:3px"><em>For help and more information:</em> </p><strong>Contact our 24 Hour Call Centre at 1800-209-5858, 1800-102-5858</strong></td>
                                    <td width="51%">&nbsp;</td>
                            </tr>
                            <tr>
                                    <td>Email: <a href="mailto:customercare@bajajallianz.co.in">customercare@bajajallianz.co.in</a> , Website <a href="http://www.bajajallianz.com">www.bajajallianz.com</a></td>
                                    <td style="text-align:right"><strong>Corporate Identification Number:  U66010PN2000PLC015329</strong></td>
                            </tr>
                            <tr>
                                    <td colspan="2"><strong>Demystify Insurance </strong><img src="image/wp.png" width="9" height="9" alt=""/> <a href="http://support.bajajallianz.com">http://support.bajajallianz.com</a> ; <img src="image/fb.png" width="8" height="9" alt=""/> <a href="https://www.facebook.com/BajajAllianz">https://www.facebook.com/BajajAllianz</a> ; <img src="image/twit.png" width="10" height="10" alt=""/> <a href="https://twitter.com/BajajAllianz">https://twitter.com/BajajAllianz</a> ; <img src="image/in.png" width="10" height="10" alt=""/> <a href="http://bit.do/bjazgi">http://bit.do/bjazgi</a></td>
                                    </tr>
                    </tbody>
            </table>

                    </div>';
        $mpdf->SetHTMLHeader($header);
        $mpdf->SetHTMLFooter($footer);

        $mpdf->SetProtection(array('print')); //cancel_certicate.png
        $mpdf->SetWatermarkImage('image/draft.png', 0.7, '', array(10, 0));
        $mpdf->showWatermarkImage = true;
        $mpdf->watermarkImgBehind = false;
        $mpdf->SetDisplayMode('fullpage');



        $mpdf->WriteHTML($html);
        $fileName = "Certificate-" . $aPolicy->certificate_no . ".pdf";
        $mpdf->Output($fileName, 'I');
        //                $mpdf->Output();
        exit;
    }

    public function actionBackValues($id)
    {
        $quoteObj = \common\models\Quotation::findOne($id);
        $quoteObj->is_draft = 1;
        if ($quoteObj->save(false, ['is_draft'])) {
            $type = strtolower($quoteObj->transit_type);
            if ($quoteObj->insurance_product_type_id == 1) {
                return $this->redirect("edit-draft-container?type=$type&id=$id&isBuy=true");
            } else {
                return $this->redirect("edit-draft?type=$type&id=$id&isBuy=true");
            }
        }
    }

    /**
     * Get the allowed back date
     */
    public function actionGetAllowedBackDate()
    {
        if (Yii::$app->request->isAjax) {
            $aRequest = Yii::$app->request->post();
            $countryId = !empty($aRequest['countryid']) ? $aRequest['countryid'] : '';
            $transitType = !empty($aRequest['type']) ? strtolower($aRequest['type']) : '';
            $data = $this->getAllowedCompanyBackDate(
                Yii::$app->user->identity->company_id,
                $transitType,
                $countryId
            );
            \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
            return [
                'allowbackdate' => $data,
            ];
        }
    }

    /**
     * Check Invoice no in last 7 days.
     */
    public function actionCheckInvoiceno()
    {
        if (Yii::$app->request->isAjax) {
            $aRequest = Yii::$app->request->post();
            $invoiceNo = !empty($aRequest['invoiceno']) ? $aRequest['invoiceno'] : '';
            $query = new \common\models\Quotation();
            $objResult = $query->getInvoiceDetails($invoiceNo, Yii::$app->user->identity->id);
            $flag = false;
            $invoiceData = '';
            if ($objResult && $invoiceNo) {
                $flag = true;
                $transitDate = date('d-m-Y', strtotime($objResult->coverage_start_date));
                $invoiceData = '<div id="odc_cargo_popup_id">
                <div style="marigin-bottom:5px;">Policy already taken against entered invoice no ' . $invoiceNo . ' on ' . $transitDate . '</div>
                    <div style="margin-top:5px;">Insured Name: ' . $objResult->institution_name . '</div>
                    <div>Certificate no.: ' . $objResult->certificate_no . '</div>
                    <div>Transit Type: ' . $objResult->transit_type . '</div>
                    <div>Invoice Amount: ' . $objResult->total_premium . '</div>
                    <div style="text-align:center;font-weight:bold;margin-top:5px">Continue with same invoice no.</div>
                    <br>
               </div>';
            }
            \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
            return [
                'invoice' => $flag,
                'invoice_data' => $invoiceData
            ];
        }
    }

    public function actionTransitUpload()
    {
        //    try {
        $fileUpload = new \frontend\insurance\models\OfflineUploads();
        $objUploadedQuote = '';
        $objBajajGroup = \common\models\CompanyPolicyCargoCertificate::checkBajajGroupExitOrNotWithInfo(Yii::$app->user->identity->company_id);
        if (isset($objBajajGroup) && $objBajajGroup->masterPolicy->bajaj_group) {
            Yii::$app->session->setFlash('error', 'UnAuthorized Access.');
            return $this->redirect(['user/policy']);
        }
        if ($fileUpload->load(Yii::$app->request->post())) {
            $aRequest = Yii::$app->request->post('OfflineUploads');
            if ($aRequest['is_declaration'] == 1) {
                $objCompanyPolicy = new \common\models\CompanyPolicyNo();
                $objResult = $objCompanyPolicy->getPolicyDetail(Yii::$app->user->identity->company_id);
                $objPolicy = \common\models\CompanyPolicyNo::findOne($objResult->id);
                //getPolicyDetail
                $objPolicy->id = $objResult->id;
                $objPolicy->is_declared = $aRequest['is_declaration'];
                $objPolicy->declaration_month = date("d-m-Y", strtotime($aRequest['transit_start_date']));
                $objPolicy->last_declaration_month = date("d-m-Y", strtotime($aRequest['transit_start_date']));
                $objPolicy->save(false, ['is_declared', 'declaration_month', 'last_declaration_month']);
            }

            $aContent = [];
            $file = $_FILES['OfflineUploads']['name']['offline_upload'];
            $ext = pathinfo($file, PATHINFO_EXTENSION);
            $csv_handler = fopen($_FILES['OfflineUploads']['tmp_name']['offline_upload'], 'r');

            $quoteModel = new \frontend\insurance\models\TempQuote();

            $objUserInfo = \common\models\User::getCompanyAdminInfoByCompanyId(Yii::$app->user->identity->company_id); 
            $quoteModel->customer_type = 'I';
            $quoteModel->contact_name = Yii::$app->user->identity->first_name . " " . Yii::$app->user->identity->last_name;
            $quoteModel->party_name = $objUserInfo->company;
            $quoteModel->mobile = $objUserInfo->mobile;
            $quoteModel->billing_address = $quoteModel->address = preg_replace('/\s+/', ' ', $objUserInfo->address);
            $quoteModel->billing_city = $quoteModel->city = $objUserInfo->city;
            $quoteModel->billing_state = $quoteModel->state = $objUserInfo->state;
            $quoteModel->billing_pincode = $quoteModel->pincode = $objUserInfo->pincode;
            $quoteModel->branch = $objUserInfo->branch;
            $quoteModel->gstin = $objUserInfo->gstin;
            $quoteModel->branch = Yii::$app->user->identity->branch;
            $quoteModel->insurance_product_type_id = 2;
            $quoteModel->user_id = Yii::$app->user->id;
            $quoteModel->billing_detail = 1;
            $quoteModel->user_detail = 1;
            $quoteModel->country = 'India';
            $quoteModel->declaration_month = date("d-m-Y", strtotime($aRequest['transit_start_date']));
            //            echo $quoteModel->transit_type;die;
            $transitTypeModel = new \common\models\TransitType();
            $transitTypeId = $transitTypeModel->getIdByTransitType($aRequest['type']);

            // $objPHPExcel = PHPExcel_IOFactory::load($_FILES['OfflineUploads']['tmp_name']['offline_upload']);
            $objPHPExcel = IOFactory::load($_FILES['OfflineUploads']['tmp_name']['offline_upload']);
            $sheetData = $objPHPExcel->getActiveSheet()->toArray(null, true, true, false);
            $i = 0;

            foreach ($sheetData as $key => $data) {
                if ($i > 0 && !empty($data[1])) {
                    $quoteModel->transit_type = 'Inland';
                    $quoteModel->origin_country = 'India';
                    $quoteModel->destination_country = 'India';
                    $quoteModel->invoice_currency = 'INR';
                    $quoteModel->exchange_rate = 1;
                    $transitModeModel = new \common\models\TransitMode();
                    $transitModeId = $transitModeModel->getIdByTransitMode($quoteModel->transit_mode);

                    $quoteModel->cargo_description = $data[0];
                    $quoteModel->commodity = $data[1];

                    $quoteModel->mark_no = $data[2];
                    $quoteModel->transit_mode = $data[3];
                    $quoteModel->location_from = $data[4];
                    $quoteModel->location_to = $data[5];
                    $quoteModel->institution_name = $data[6];

                    $strReplaceCoverage = str_replace('/', '-', $data[7]);
                    $aCoverageDt = explode('-', $strReplaceCoverage);
                    if (count($aCoverageDt) > 0) {
                        $quoteModel->coverage_start_date = date('Y-m-d',  strtotime($strReplaceCoverage));
                    }

                    $quoteModel->invoice_no = $data[8];
                    $strReplaceInovice = str_replace('/', '-', $data[9]);
                    $aInvoiceDt = explode('-', $strReplaceInovice);
                    if (count($aInvoiceDt) > 0) {
                        $quoteModel->invoice_date = date('Y-m-d',  strtotime($strReplaceInovice));
                    }

                    $quoteModel->receipt_no = $data[10];
                    $quoteModel->receipt_date = '';
                    if (!empty($data[11])) {
                        $strReplaceReceipt = str_replace('/', '-', $data[11]);
                        $areceiptDt = explode('-', $strReplaceReceipt);
                        if (count($areceiptDt) > 0) {
                            $quoteModel->receipt_date = date('Y-m-d',  strtotime($strReplaceReceipt));
                        }
                    }

                    $quoteModel->authority_name = $data[12];
                    $quoteModel->authority_detail = $data[13];
                    $quoteModel->buyer_details = $data[14];
                    $quoteModel->seller_details = $data[15];
                    $quoteModel->reference_no = $data[16];
                    $quoteModel->additional_details = $data[17];
                    $quoteModel->invoice_amount_inr = $quoteModel->invoice_amount = $data[18];
                    $quoteModel->extra_percentage_amount = (!empty($data[19]) || $data[19] == 0) ? $data[19] : 10;
                    $quoteModel->sum_insured = $this->calculateSumInsured($data[18], $data[19]);
                    $objState = \common\models\DgnoteTrailerState::find()
                        ->where(['name' => $quoteModel->billing_state])->one();
                    if ($quoteModel->commodity) {
                        $aPremium = $this->premiumCalculation(
                            $transitTypeId,
                            $transitModeId,
                            2,
                            $quoteModel->commodity,
                            $quoteModel->sum_insured,
                            $movement = 'S',
                            $quoteModel->w2w,
                            $gstin = '',
                            $objState->state_code,
                            $quoteModel->coverage,
                            $billingType = ''
                        );
                        if (count($aPremium) > 0) {
                            $quoteModel->premium = $aPremium['premium'];
                            $quoteModel->service_tax_amount = str_replace(',', '', $aPremium['service_tax_amount']);
                            $quoteModel->stamp_duty_amount = $aPremium['stamp_duty_amount'];
                            $quoteModel->total_premium = str_replace(',', '', $aPremium['total_premium']);
                        }
                        //                                if($flag) break;
                    }
                    $quoteModel->surveyor_id = 631;
                    $quoteModel->surveyor_country = 'India';
                    $quoteModel->surveyor_city = 'Mumbai';
                    $quoteModel->surveyor_agent = 'Bajaj Allianz General Insurance Co. Limited-India';
                    $quoteModel->surveyor_address = '952/954Appa Saheb Marathe Marg, Prabhadevi, Mumbai 400025, Maharashtra, Tel:56628666/56628621Tel, India';
                    $quoteModel->id = NULL;
                    $quoteModel->isNewRecord = TRUE;
                    if ($transitTypeId && $quoteModel->commodity) {
                        $quoteModel->valuation_basis = $this->getBov($transitTypeId, $quoteModel->commodity);
                    }
                    if ($quoteModel->save(false)) {
                    }
                }
                $i++;
            }
        }

        $objUploadedQuote = \frontend\insurance\models\TempQuote::find()
            ->where(['user_id' => Yii::$app->user->identity->id])
            ->all();

        return $this->render('transit_upload', [
            'balance' => $this->getUserBalance(),
            'fileUpload' => $fileUpload,
            'objUploadedQuote' => $objUploadedQuote,
            'objBajajGroup' => $objBajajGroup
        ]);
        //    } catch (\Exception $ex) {
        //        \Yii::$app->commonMailSms->sendMailAtException("","Issue in Offline Upload function",$ex->getMessage());
        //        Yii::$app->session->setFlash('error', 'There is some issue in uploaded file data. Kindly check the records.');
        //        return $this->redirect(['offline-upload/index']);
        //    }
    }

    private function calculateSumInsured($amount, $ExtraPercenage, $exchangeRate = 1)
    {
        $amount = $amount * $exchangeRate;
        $percentage = ($amount * $ExtraPercenage / 100);
        return round($amount + $percentage);
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

    public function actionGenerateCertificate()
    {
        //        try {
        $fileUpload = new \frontend\insurance\models\OfflineUploads();
        if ($fileUpload->load(Yii::$app->request->post())) {
            $aRequest = Yii::$app->request->post();
            // delete functionality..
            if (isset($aRequest['delete_all'])) {
                $aCheckBox = array_keys($aRequest['OfflineUploads']['uploaded_id']);
                if (count($aCheckBox) > 0) {
                    foreach ($aCheckBox as $key => $value) {
                        $objQuote = \frontend\insurance\models\TempQuote::findOne($value);
                        $objQuote->delete();
                    }
                    Yii::$app->session->setFlash('success', 'Your information has been deleted successfully.');
                    return $this->redirect(['quotation/transit-upload']);
                }
            }
        }

        $aRequest = Yii::$app->request->post();
        // Validation//
        if (isset($aRequest['validate-button'])) {
            $objResult = \frontend\insurance\models\TempQuote::find()
                ->where(['user_id' => Yii::$app->user->identity->id])
                ->all();
            $currentDt = date("Y-n-d");
            $previoumMnth = date('n', strtotime('-1 months'));
            $currentDate = date('d');
            foreach ($objResult as $key => $objOne) {
                $value = $objOne->id;
                $objQuote = \frontend\insurance\models\TempQuote::findOne($value);
                $objCargo = new \frontend\insurance\models\CargoForm();
                $objCargo->setCargoForOffline($objQuote);
                $objCargo->setScenario(strtolower($objQuote->transit_type));
                $aCommodity = $objCargo->getAllCommodities();
                $aError = [];
                $objCargo->tnc = 1;
                $objCargo->is_uploaded = 1;
                $objCargo->transit_mode = ucfirst(strtolower(trim($objCargo->transit_mode)));
                if ($objCargo->validate(['coverage_start_date'])) {
                    $objCargoCertificate = \common\models\CompanyPolicyCargoCertificate::checkBajajGroupExitOrNotWithInfo(Yii::$app->user->identity->company_id);
                    if (strtotime($objOne['declaration_month']) > strtotime($objQuote->coverage_start_date)) {
                        $aError['previous_month'] = 'Coverage start date must be greater than or equalto ' . $objOne['declaration_month'] . '.';
                    }
                    $objCheckInvNo = \frontend\insurance\models\TempQuote::find()
                        ->where([
                            'invoice_no' => $objQuote->invoice_no,
                            'user_id' => Yii::$app->user->identity->id
                        ])
                        ->andWhere(['!=', 'id', $objQuote->id])
                        ->all();
                    if ($objCheckInvNo) {
                        $aError['error'] = 'Invoice number input is duplicated in uploaded data.';
                    }
                    $covDate = (strtotime($objQuote->coverage_start_date) > 0) ? date('Y-m-d', strtotime($objQuote->coverage_start_date)) : 0;
                    $recDate = (strtotime($objQuote->receipt_date) > 0) ? date('Y-m-d', strtotime($objQuote->receipt_date)) : 0;
                    if ($covDate == 0) {
                        $aError['coverage_date'] = 'Invoice Date input is incorrect. '
                            . 'Kindly review standard input date field format dd/mm/yyyy.';
                    }
                    if (!empty($objQuote->receipt_date) && $recDate == 0) {
                        $aError['receipt_date'] = 'BL/AWB/RR/LR Date input is incorrect. '
                            . 'Kindly review standard input date field format dd/mm/yyyy.';
                    }
                    $commodityModel = new \common\models\Commodity();
                    $commodityId = $commodityModel->getIdByCommodity($objQuote->commodity);

                    $cmnRtMdl = $this->isCertificate(Yii::$app->user->identity->company_id, 2, $commodityId);
                    //                        echo $objQuote->billing_state;die;
                    $objQuote->billing_state = \frontend\insurance\models\TempQuote::getStateCodeByName($objQuote->billing_state);
                    $validationObj = new \frontend\insurance\components\MasterDataValidatonComponent();
                    $aError1 = $validationObj->checkServerValidation($objQuote, 'noncontainer', $cmnRtMdl);
                    if (empty($aError1['status'])) {
                        $aError['error'] = $aError1['error'];
                    }
                    if (!array_key_exists($objQuote->commodity, $aCommodity)) {
                        $aError['commodity'] = 'Commodity input is incorrect. Kindly review standard commodity format.';
                    }
                    $transitTypeModel = new \common\models\TransitType();
                    $transitTypeId = $transitTypeModel->getIdByTransitType($objQuote->transit_type);
                    $transitModeModel = new \common\models\TransitMode();
                    $transitModeId = $transitModeModel->getIdByTransitMode($objQuote->transit_mode);
                    if ($objQuote->transit_type == 'Import') {
                        $objSlTerm = new \common\models\SaleTerms();
                        $idSlTerm = $objSlTerm->getSaleTermIdByValueWithLIKE($objQuote->terms_of_sale);
                        $objCountry = \common\models\Country::find()
                            ->where(['name' => $objQuote->origin_country])->one();

                        if ($objQuote->destination_country == 'India') {
                            $aError['destination_country'] = 'Origin Country must be a India';
                        }

                        // $hraFlag = $this->checkHRACountryOpenPolicy();
                        // if($hraFlag && $objCountry->country_category=='H'){
                        //     $aError['origin_country'] = 'Origin Country cannot be a HRA country.';
                        // }
                        /// Need to check w2w is correct or not..
                        $objQuote->coverage = $this->getCoverage($transitTypeId, $transitModeId, 1, $idSlTerm);
                        $objQuote->coverage_war = $this->getConverageWar($transitTypeId, $transitModeId);
                    } elseif ($objQuote->transit_type == 'Export') {
                        $objCountry = \common\models\Country::find()
                            ->where(['name' => $objQuote->destination_country])->one();
                        if ($objQuote->destination_country == 'India') {
                            $aError['destination_country'] = 'Origin Country must be a India';
                        }

                        // $hraFlag = $this->checkHRACountryOpenPolicy();
                        // if($hraFlag && $objCountry->country_category=='H'){
                        //     $aError['destination_country'] = 'Destination Country cannot be a HRA country.';
                        // }

                        if ($objQuote->w2w == 1) {
                            $objCoverage = \common\models\CoverageType::find()
                                ->where(['type' => $objQuote->coverage])->one();
                            $objSlTerm = new \common\models\SaleTerms();
                            $idSlTerm = $objSlTerm->getSaleTermIdByValueWithLIKE($objQuote->terms_of_sale);
                            $transitModeModel = new \common\models\TransitMode();
                            $transitModeId = $transitModeModel->getIdByTransitMode($objQuote->transit_mode);

                            $productModel = new \common\models\InsuranceProduct();
                            $aInsuranceProduct = $productModel->getProductCodeByMatrix(
                                \frontend\insurance\models\CargoForm::NON_CONTAINER_PRODUCT_ID,
                                $transitTypeId,
                                $transitModeId
                            );
                            $w2wMdl = new \common\models\W2W();
                            $data = $w2wMdl->getW2W(
                                $transitTypeId,
                                $transitModeId,
                                $idSlTerm,
                                $objCountry->id,
                                174,
                                $objCoverage->id,
                                $aInsuranceProduct['id']
                            );
                            if (!$data) {
                                $aError['w2w'] = 'Warehouse to Warehouse is not allowed for ' . $objQuote->terms_of_sale;
                            }
                            if ($objCargoCertificate->masterPolicy->is_w2w == 0) {
                                $aError['w2w'] = 'Warehouse to Warehouse is not applicable, please contact with DgNote Administrator.';
                            }
                            $objQuote->coverage = $this->getCoverage($transitTypeId, $transitModeId, 1, $idSlTerm);
                            $objQuote->coverage_war = $this->getConverageWar($transitTypeId, $transitModeId);
                        }
                    } else {
                        /// Need to check w2w is correct or not..
                        $objQuote->coverage = $this->getCoverage($transitTypeId, $transitModeId, 1);
                        $objQuote->coverage_war = $this->getConverageWar($transitTypeId, $transitModeId);
                    }

                    $objMode = \common\models\TransitMode::find()->where(['mode' => $objQuote->transit_mode])->one();
                    if (!$objMode) {
                        $aError['transit_mode'] = 'Transit Mode input is incorrect.  '
                            . 'Kindly review standard transit mode format.';
                    }
                    $objSaleTerms = '';
                    if ($objQuote->transit_type != 'Inland') {
                        $objSaleTerms = \common\models\SaleTerms::find()->where(['LIKE', 'term', $objQuote->terms_of_sale])->one();
                        if (!$objSaleTerms) {
                            $aError['terms_of_sale'] = 'Terms of Sale input is incorrect.  '
                                . 'Kindly review standard terms of sale format.';
                        }
                    }
                    $maxSumInsured = isset($objCargoCertificate->masterPolicy->max_sum_insured) ?
                        $objCargoCertificate->masterPolicy->max_sum_insured : \Yii::$app->params['maxInsurancePremium'];
                    if ($objQuote->sum_insured >= $maxSumInsured) {
                        $aError['sum_insured'] = "Sum insured input should not be greater than Rs. $maxSumInsured.";
                    }
                    $packaging = '';
                    if (isset($objMode->id)) {
                        $packaging = $this->getPacking($commodityId, $transitTypeId, $objMode->id);
                    }

                    $dgnoteRate = Yii::$app->commonutils
                        ->getMasterPolicyNo(
                            $objQuote->insurance_product_type_id,
                            $objQuote->user->company_id,
                            $objQuote->coverage,
                            $objQuote->is_odc,
                            $objQuote->country_type
                        );

                    if (!$dgnoteRate) {
                        $aError['policy_no'] = "Policy is not mapped, please contact with DgNote Administrator.";
                    }

                    if ($this->checkInvoice($objQuote->invoice_no, $objQuote->user_id)) {
                        $aError['invoice_no'] = "Invoice no. is already exist in the system.";
                    }

                    // $blanceModel = new \common\models\UserAccountBalance();
                    $accountbalance = new \common\models\UserAccountBalance();
                    $companyBalance = $accountbalance->getUserAccountBalance(Yii::$app->user->identity->id);
                    // $companyBalance = $blanceModel->getCompanyPolicyBalance(\yii::$app->user->identity->company_id);
                    if ($objQuote->total_premium > $companyBalance) {
                        $aError['total_premium'] = "Your policy balance is not sufficient to purchase this policy. Please add amount to your policy balance.";
                    }

                    if (count($aError) > 0) {
                        $objQuote->packing = $packaging;
                        $objQuote->status = 2;
                        $error = json_encode($aError);
                        $objQuote->upload_error = $error;
                        $objQuote->update(FALSE, ['upload_error', 'status', 'packing']);
                    } else {
                        $objQuote->packing = $packaging;
                        $objQuote->status = 1;
                        $objQuote->upload_error = '';
                        $objQuote->update(FALSE, ['upload_error', 'status', 'packing', 'coverage', 'coverage_war']);
                    }
                } else {
                    $aErrors = $objCargo->getErrors();
                    $error = '';
                    $objQuote->status = 1;
                    if ($aErrors) {
                        foreach ($aErrors as $key => $aValue) {
                            if ($key !== 'tnc' && $key != 'packing') {
                                $aError[$key] = $aValue[0];
                            }
                        }
                    }
                    if (count($aError) > 0) {
                        $objQuote->status = 2;
                        $error = json_encode($aError);
                    }
                    $objQuote->upload_error = $error;
                    $objQuote->update(FALSE, ['upload_error', 'status']);
                }
            }
            return $this->redirect(['quotation/transit-upload']);
        }

        // submit functionality...
        if (isset($aRequest['submit-button'])) {

            //                $aCheckBox = array_keys($aRequest['OfflineUploads']['uploaded_id']);
            $objResult = \frontend\insurance\models\TempQuote::find()
                ->where(['user_id' => Yii::$app->user->identity->id])
                ->all();
            foreach ($objResult as $key => $objOne) {
                $value = $objOne->id;
                $objQuote = \frontend\insurance\models\TempQuote::findOne($value);

                $group = $this->getBajaGroup($objQuote->commodity, Yii::$app->user->identity->company_id);
                $groupName = isset($group->masterPolicy->bajaj_group) ? $group->masterPolicy->bajaj_group : '';

                $objCargo = new \frontend\insurance\models\CargoForm();
                $objCargo->assignCargoForOffline($objQuote);
                $objCargo->bajaj_group = '';
                $objCargo->is_uploaded = 1;
                $objCargo->upload_file_status = 'success';
                $objCargo->tnc = 1;
                $objCargo->is_commenced = 0;
                $transitTypeModel = new \common\models\TransitType();
                $transitTypeId = $transitTypeModel->getIdByTransitType($objQuote->transit_type);
                if ($transitTypeId && $objCargo->commodity) {
                    $objCargo->valuation_basis = $this->getBov($transitTypeId, $objCargo->commodity);
                }
                if ($quoteObj = $objCargo->save()) {
                    $objState = \common\models\DgnoteTrailerState::find()
                        ->where(['state_code' => $objCargo->billing_state])->one();
                    $objCargo->billing_state = $objState->name;
                    $this->insertPermiumCalculation(
                        $objCargo->commodity,
                        $quoteObj,
                        $objCargo->transit_type,
                        $objCargo->transit_mode,
                        $group,
                        $objCargo->country_type
                    );
                    $objPaymentController = new PaymentController('controller', 'insurance');
                    $response = $objPaymentController->policyPaymentForInlandUpload($quoteObj->id);
                    if ($response['status'] != 'success') {
                        $objQuote->upload_error = isset($response['message']) ? json_encode($response['message']) : '';
                        $objQuote->status = 2;
                        $objQuote->update(FALSE, ['upload_error', 'status']);
                        Yii::$app->session->setFlash('error', 'Please check errors in Listing');
                        return $this->redirect(['quotation/transit-upload']);
                    } else {
                        $quoteObj->upload_error = json_encode($response['msg']);
                        $quoteObj->upload_file_status = 'success';
                        $quoteObj->update(FALSE, ['upload_error', 'upload_file_status']);
                        // $this->updateLastDeclartion($quoteObj->coverage_start_date);
                        // $this->updateMasterPolicy($group->masterPolicy, $objQuote->declaration_month);
                        $objQuote->delete();
                        Yii::$app->session->setFlash('sucess', 'Certificates are generated successfully.');
                    }
                } else {
                    $error = '';
                    if ($objCargo->getErrors()) {
                        foreach ($objCargo->getErrors() as $key => $aValue) {
                            $aError[$key] = $aValue[0];
                        }
                        $error = json_encode($aError);
                    }
                    $objQuote->upload_error = $error;
                    $objQuote->status = 2;
                    $objQuote->update(FALSE, ['upload_error', 'status']);
                    Yii::$app->session->setFlash('sucess', 'Certificates are generated successfully.');
                    return $this->redirect(['quotation/transit-upload']);
                }
            }
            Yii::$app->session->setFlash('success', 'Certificates are generated successfully.');
            return $this->redirect(['user/policy']);
        }
        //        } catch (\Exception $ex) {
        //            \Yii::$app->commonMailSms->sendMailAtException("","Issue in Offline Upload on Generate Ceritifcate function",$ex->getMessage());
        //            return $this->redirect(['offline-upload/index']);
        //        }
    }

    private function getCoverage($transitTypeId, $transitModeId, $flag = 1, $saleTermId = null)
    {
        $coverModel = new \common\models\CoverageType();
        $coverageType = $coverModel->getCoverageTypeBySaleTermWithNewData(
            $saleTermId,
            $transitTypeId,
            $transitModeId,
            $flag
        );
        $coverage = '';
        if (count($coverageType) > 0) {
            foreach ($coverageType as $obj) {
                $coverage = $obj->type;
                break;
            }
        }
        return $coverage;
    }

    private function getConverageWar($transitTypeId, $transitModeId)
    {
        $coverageModel = new \common\models\CoverageWar();
        return $coverageModel->getCoverageWar($transitTypeId, $transitModeId);
    }

    private function getPacking($commodityId, $transitTypeId, $transitModeId)
    {
        $query = \common\models\Packaging::find();
        $query->select(['code']);
        $query->join('INNER JOIN', 'mi_packaging_matrix as b', 'b.packaging_id = mi_packaging.id');
        $query->where([
            'b.commodity_id' => $commodityId,
            'b.transit_type_id' => $transitTypeId, 'b.transit_mode_id' => $transitModeId
        ]);
        $query->andWhere(['mi_packaging.status' => 1, 'b.status' => 1]);
        //        return $aResult = $query->asArray()->all();
        //        $query = \common\models\Packaging::find();
        //        $query->select(['mi_packaging.code'])
        //                ->where(['transit_mode_id' =>$transitModeId]);
        //        $command = $query->createCommand();
        return $query->createCommand()->queryScalar();
    }

    private function checkInvoice($invoiceNo, $userId)
    {
        $startDate = date("Y-m-d", strtotime("-1 months"));
        $endDate = date("Y-m-d");
        $query = \common\models\Quotation::find();
        $query->join(
            'INNER JOIN',
            'dgnote_transactions',
            '`dgnote_transactions`.`quote_id`=`mi_quote`.`id`'
        );
        $query->where(['dgnote_transactions.status' => 'success']);
        $query->andWhere(['mi_quote.invoice_no' => $invoiceNo, 'mi_quote.user_id' => $userId]);
        $query->andWhere([">=", 'date(mi_quote.coverage_start_date)', $startDate]);
        $query->andWhere(["<=", 'date(mi_quote.coverage_start_date)', $endDate]);
        if ($query->all()) {
            return true;
        }
        return false;
    } 

    public function actionDeleteOfflineCargo($id)
    {
        if ($id) {
            $objTemp = \frontend\insurance\models\TempQuote::find()
                ->where(['user_id' => Yii::$app->user->identity->id, 'id' => $id])->one();
            if ($objTemp) {
                $objTemp->delete();
                Yii::$app->session->setFlash('success', 'Your information has been deleted successfully!');
            } else {
                Yii::$app->session->setFlash('error', 'Invalid Information!');
            }
        } else {
            Yii::$app->session->setFlash('error', 'Invalid Information!');
        }
        return $this->redirect(['quotation/transit-upload']);
    }

    public function actionDownloadErrorFile()
    {
        $objTemp = \frontend\insurance\models\TempQuote::find()
            ->where(['user_id' => Yii::$app->user->identity->id])
            ->all();
        if (count($objTemp) > 0) {
            $filename = Yii::$app->params['uploadPath'] . "/OfflineUploadErrorsFile_" . date('Y-m-d') . ".csv";
            $csv_handler = fopen($filename, 'w');
            $header = [
                'Type', 'Invoice No.', 'Commodity', 'Transit Start Date', 'Invoice Amount', 'Extra%', 'Sum Insured', 'Status', 'Errors'
            ];
            fputcsv($csv_handler, $header);
            foreach ($objTemp as $objValue) {
                $type = $objValue->transit_type;
                $invoice_no = $objValue->invoice_no;
                $commodity = $objValue->commodity;
                $coverage_start_date = (strtotime($objValue->coverage_start_date) > 0) ?
                    date('d-M-Y', strtotime($objValue->coverage_start_date)) : '';
                $invoice_amount = $objValue->invoice_amount;
                $extra = $objValue->extra_percentage_amount;
                $sum_insured = $objValue->sum_insured;
                $aJson = json_decode($objValue->upload_error, true);
                $err = '';
                $i = 1;
                if (count($aJson) > 0) {
                    foreach ($aJson as $error) {
                        $err .= $i . '.' . $error . "\r\n";
                        $i++;
                    }
                }
                $status = '';
                if ($objValue->status == 1) {
                    $status = 'OK';
                } elseif ($objValue->status == 2) {
                    $status = 'Error';
                }
                $line = [
                    $type, $invoice_no, $commodity, $coverage_start_date, $invoice_amount, $extra, $sum_insured, $status, $err
                ];
                fputcsv($csv_handler, $line);
            }

            $line = ['', '', '', '', '', '', '', '', '', '', '', ''];
            fputcsv($csv_handler, $line);
            if (file_exists($filename)) {
                Yii::$app->response->SendFile($filename);
            }
        }
    }

    public function actionEditTransitUpload($type, $id, $isBuy = false)
    {
        $objQuote = \frontend\insurance\models\TempQuote::find()
            ->where(['transit_type' => $type, 'id' => $id, 'insurance_product_type_id' => 2])
            ->andWhere(['user_id' => Yii::$app->user->identity->id])
            ->one();
        if ($objQuote) {
            $accountbalance = new \common\models\UserAccountBalance();
            $balance = $accountbalance->getUserAccountBalance(Yii::$app->user->identity->id);
            $objSez = $this->getSEZ();
            $cargoModel = new \frontend\insurance\models\CargoForm();
            if ($type == 'export') {
                $cargoModel->setScenario('offline_export');
            }

            $cargoModel->setCargoForOffline($objQuote);

            $aCommodity = $cargoModel->getAllCommodities();
            $backDate = $objQuote['declaration_month'];
            // $currentDate = date('d-m-Y');
            // if (strtotime($currentDate) > strtotime($backDate['endDate'])) {
            //     $cargoModel->coverage_start_date = '';
            // }
            if ($cargoModel->load(Yii::$app->request->post())) {

                $aRequest = Yii::$app->request->post();


                $isDraft = isset($aRequest['Draft']) ? 1 : 0;

                if (!$this->checkCompanyCredit($balance, $aRequest['CargoForm']['total_premium'])) {
                    Yii::$app->session->setFlash('error', 'Payment is declined due to insufficient credit limit, please contact DgNote Administrator!');
                } else {
                    $checSumInsured = $aRequest['CargoForm']['invoice_amount'];
                    unset($aRequest['CargoForm']['comma_sum_insured']);
                    $this->saveUserContactDetails(
                        $aRequest['CargoForm']['institution_name'],
                        $aRequest['CargoForm']['address'],
                        $aRequest['CargoForm']['city'],
                        $aRequest['CargoForm']['state'],
                        $aRequest['CargoForm']['pincode'],
                        $aRequest['CargoForm']['gstin'],
                        $aRequest['CargoForm']['party_name'],
                        $aRequest['CargoForm']['billing_city'],
                        $aRequest['CargoForm']['billing_state'],
                        $aRequest['CargoForm']['billing_address'],
                        $aRequest['CargoForm']['billing_pincode']
                    );
                    $productModel = new \common\models\InsuranceProduct();
                    $transitTypeModel = new \common\models\TransitType();
                    $transitTypeId = $transitTypeModel->getIdByTransitType($cargoModel->transit_type);
                    $transitModeModel = new \common\models\TransitMode();
                    $transitModeId = $transitModeModel->getIdByTransitMode($cargoModel->transit_mode);
                    $aInsuranceProduct = $productModel->getProductCodeByMatrix(
                        \frontend\insurance\models\CargoForm::NON_CONTAINER_PRODUCT_ID,
                        $transitTypeId,
                        $transitModeId
                    );
                    $objQuote->branch = Yii::$app->user->identity->branch;
                    $objQuote->company_id = Yii::$app->user->identity->company_id;
                    $objQuote->product_code = $aInsuranceProduct['code'];

                    $objQuote->valuation_basis = ($aRequest['CargoForm']['valuation_basis'] == 'Terms Of Sale') ? 'TOS' : $aRequest['CargoForm']['valuation_basis'];
                    $objQuote->contact_name = Yii::$app->user->identity->first_name . " " . Yii::$app->user->identity->last_name;
                    $objQuote->mobile = Yii::$app->user->identity->mobile;
                    $objQuote->country = Yii::$app->user->identity->country;
                    $objQuote->total_premium = $this->removeCommaFromAmount($aRequest['CargoForm']['total_premium']);

                    $objQuote->premium = $this->removeCommaFromAmount($aRequest['CargoForm']['premium']);
                    $objQuote->gstin = $aRequest['CargoForm']['gstin'];
                    $objQuote->pan = \yii::$app->gst->getPanFromGSTNo($objQuote->gstin);
                    $objQuote->pincode = $aRequest['CargoForm']['pincode'];
                    $objQuote->w2w = isset($aRequest['CargoForm']['w2w']) ? $aRequest['CargoForm']['w2w'] : 0;
                    $objQuote->user_detail = (isset($aRequest['user_detail']) && $aRequest['user_detail'] == 'on') ? 1 : 0;
                    $objQuote->billing_detail = isset($aRequest['billing_detail'][0]) ? $aRequest['billing_detail'][0] : 0;
                    $objQuote->is_sez = isset($aRequest['CargoForm']['is_sez']) ? $aRequest['CargoForm']['is_sez'] : 0;
                    $objQuote->transit_mode = isset($aRequest['CargoForm']['transit_mode']) ? $aRequest['CargoForm']['transit_mode'] : 0;
                    $sezFlag = false;
                    $objQuote->service_tax_amount = $this->removeCommaFromAmount($aRequest['CargoForm']['service_tax_amount']);
                    if ($objSez->is_sez == 2 && $objQuote->billing_detail == 2 && $objQuote->is_sez == 1) {
                        $objQuote->service_tax_amount = 0;
                        $sezFlag = true;
                    } elseif ($objSez->is_sez == 1 && $objQuote->billing_detail == 1 && $objQuote->is_sez == 1) {
                        $objQuote->service_tax_amount = 0;
                        $sezFlag = true;
                    }

                    $commodityModel = new \common\models\Commodity();

                    $commodityId = $commodityModel->getIdByCommodity($aRequest['CargoForm']['commodity']);
                    $companyId = Yii::$app->user->identity->company_id;
                    if (\common\models\CompanyPolicyCargoCertificate::checkBajajGroupExitOrNot($companyId)) {
                        $objGroup = \common\models\CompanyPolicyCargoCertificate::checkBajajRateGroupForPolicy($commodityId, $companyId);
                        if (!$objGroup) {
                            // send mail for admin that policy is not mapped for that company
                            Yii::$app->session->setFlash('error', 'Commodity not configure please contact DgNote Administrator!');
                            return $this->redirect("edit-transit-upload?type=$type&id=$id");
                        } else {
                            $dgnoteRate = Yii::$app->commonutils->getMasterPolicyNo(
                                2,
                                $companyId,
                                $objQuote->coverage,
                                $objQuote->is_odc,
                                $objQuote->country_type
                            );
                            $objQuote->bajaj_group = $dgnoteRate->bajaj_group;
                        }
                    }

                    $cmnRtMdl = $this->isCertificate(Yii::$app->user->identity->company_id, 2, $commodityId);
                    if ($cmnRtMdl) {
                        $objQuote->dgnote_commission = $this->getDgnoteRate($cargoModel->commodity, $objQuote->w2w);
                        if ($aRequest['CargoForm']['transit_type'] == 'Export') {
                            if (!empty($aRequest['CargoForm']['surveyor_city']) && $aRequest['CargoForm']['surveyor_city'] == 'NA') {
                                $objQuote->surveyor_country = $objQuote->destination_country;
                                $objQuote->surveyor_address = $aRequest['CargoForm']['surveyor_address'];
                                $objQuote->surveyor_agent = $aRequest['CargoForm']['surveyor_agent'];
                            } else {
                                $survAgentResutl = $this->getSurveyoragent(
                                    $aRequest,
                                    $commodityId,
                                    $objQuote->destination_country,
                                    $transitTypeId,
                                    $aRequest['CargoForm']['terms_of_sale']
                                );
                                $objQuote->surveyor_address = $survAgentResutl['surveyor_address'];
                                $objQuote->surveyor_id = $survAgentResutl['surveyor_id'];
                                $objQuote->surveyor_agent = $survAgentResutl['surveyor_agent'];
                                $objQuote->surveyor_city = $survAgentResutl['surveyor_city'];
                            }
                        } else {
                            $objQuote->surveyor_country = $objQuote->destination_country;
                            $objQuote->surveyor_address = $aRequest['CargoForm']['surveyor_address'];
                            $objQuote->surveyor_agent = $aRequest['CargoForm']['surveyor_agent'];
                        }

                        //                            $objQuote->upload_invoice = $upload_invoice!=''?$upload_invoice->name:"";
                        //                            $objQuote->upload_packing_list = $upload_packing_list!=''?$upload_packing_list->name:"";
                        //                            $objQuote->upload_offline_format = $upload_offline_format!=''? $upload_offline_format->name:"";


                        if ($objQuote->validate()) {
                            $objQuote->coverage = $aRequest['CargoForm']['coverage'];
                            $objQuote->mark_no = $aRequest['CargoForm']['mark_no'];
                            $objQuote->authority_detail = $aRequest['CargoForm']['authority_detail'];
                            $objQuote->additional_details = $aRequest['CargoForm']['additional_details'];
                            $objQuote->business_area = !empty($aRequest['CargoForm']['business_area']) ? $aRequest['CargoForm']['business_area'] : '';
                            $objQuote->extra_percentage_amount = $aRequest['CargoForm']['extra_percentage_amount'];
                            //                                $objQuote->billing_gst = $aRequest['CargoForm']['billing_gst'];
                            $objQuote->additional_freight = $aRequest['CargoForm']['additional_freight'];
                            $objQuote->stamp_duty_amount = $aRequest['CargoForm']['stamp_duty_amount'];
                            $objQuote->coverage_start_date = $aRequest['CargoForm']['coverage_start_date'];
                            $objQuote->invoice_currency = $aRequest['CargoForm']['invoice_currency'];
                            $objQuote->invoice_amount = $aRequest['CargoForm']['invoice_amount'];
                            $objQuote->invoice_amount_inr = $aRequest['CargoForm']['invoice_amount_inr'];
                            $objQuote->sum_insured = $aRequest['CargoForm']['sum_insured'];
                            $objQuote->service_tax_amount = $aRequest['CargoForm']['service_tax_amount'];
                            $objQuote->stamp_duty_amount = $aRequest['CargoForm']['stamp_duty_amount'];
                            $objQuote->premium = $aRequest['CargoForm']['premium'];
                            $objQuote->packing = $aRequest['CargoForm']['packing'];
                            $objQuote->exchange_rate = $aRequest['CargoForm']['exchange_rate'];
                            $objQuote->invoice_amount_inr = $aRequest['CargoForm']['invoice_amount_inr'];
                            $objQuote->surveyor_city = $aRequest['CargoForm']['surveyor_city'];
                            $objQuote->receipt_no = $aRequest['CargoForm']['receipt_no'];
                            $objQuote->authority_name = $aRequest['CargoForm']['authority_name'];
                            $objQuote->reference_no = $aRequest['CargoForm']['reference_no'];
                            $objQuote->additional_duty = $aRequest['CargoForm']['additional_duty'];
                            $objQuote->invoice_amount_inr = $aRequest['CargoForm']['invoice_amount_inr'];
                            $objQuote->surveyor_id = $aRequest['CargoForm']['surveyor_id'];
                            $objQuote->terms_of_sale = !empty($aRequest['CargoForm']['terms_of_sale']) ? $aRequest['CargoForm']['terms_of_sale'] : '';
                            $objQuote->commodity = $aRequest['CargoForm']['commodity'];
                            $objQuote->destination_country = $aRequest['CargoForm']['destination_country'];
                            $objQuote->location_to = $aRequest['CargoForm']['location_to'];
                            $objQuote->location_from = $aRequest['CargoForm']['location_from'];
                            $objQuote->invoice_no = $aRequest['CargoForm']['invoice_no'];
                            $objQuote->invoice_date = $aRequest['CargoForm']['invoice_date'];
                            $objQuote->buyer_details = $aRequest['CargoForm']['buyer_details'];
                            $objQuote->seller_details = $aRequest['CargoForm']['seller_details'];
                            $objQuote->invoice_currency = $aRequest['CargoForm']['invoice_currency'];
                            $objQuote->origin_country = $aRequest['CargoForm']['origin_country'];
                            $objQuote->extra_percentage_amount = $aRequest['CargoForm']['extra_percentage_amount'];
                            $objQuote->receipt_date = $aRequest['CargoForm']['receipt_date'];
                            $objQuote->billing_state = $aRequest['CargoForm']['billing_state'];
                            $objQuote->country_type = isset($aRequest['CargoForm']['country_type']) ? $aRequest['CargoForm']['country_type'] : '';
                            $objQuote->upload_error = "";

                            $validationObj = new \frontend\insurance\components\MasterDataValidatonComponent();
                            $aError = $validationObj->checkServerValidation($objQuote, 'noncontainer', $cmnRtMdl);
                            if ($aError['status']) {
                                if ($sezFlag) {
                                    $serailze =
                                        \yii::$app->gst->getSeralizedGSTWithOutRound(
                                            $objQuote->premium,
                                            $objQuote->total_premium,
                                            $objQuote->billing_state
                                        );
                                    $aUnSerailize = unserialize($serailze);
                                    $aUnSerailize['igst'] = 0;
                                    $aUnSerailize['sgst'] = 0;
                                    $aUnSerailize['cgst'] = 0;
                                    $objQuote->service_tax_attributes = serialize($aUnSerailize);
                                } else {
                                    $objQuote->service_tax_attributes =
                                        \yii::$app->gst->getSeralizedGSTWithOutRound($objQuote->premium, $objQuote->total_premium, $objQuote->billing_state);
                                }
                                if ($quote = $objQuote->saveForOffline($objQuote->id)) {
                                    Yii::$app->session->setFlash('success', 'Your policy has been updated successfully.');
                                    return $this->redirect(['quotation/transit-upload']);
                                } else {
                                    Yii::$app->session->setFlash('error', 'There is some error please try again.');
                                    return $this->redirect("edit-transit-upload?type=$type&id=$id");
                                }
                            } else {
                                Yii::$app->session->setFlash('error', $aError['error']);
                                return $this->redirect("edit-transit-upload?type=$type&id=$id");
                            }
                        } else {
                            $aError = $cargoModel->getErrors();
                            foreach ($aError as $key => $value) {
                                Yii::$app->session->setFlash('error', $value[0]);
                                break;
                            }
                        }
                    } else {
                        Yii::$app->session->setFlash('error', 'Commodity Rates are not configure, please contact DgNote Administrator!');
                    }
                }
            }

            //            $backDate = Yii::$app->params['allowBackDays'];
            return $this->render('edit_transit_upload', [
                'model' => $cargoModel,
                'balance' => $balance,
                'cargotype' => $type,
                'id' => $id,
                'backDate' => $backDate,
                'flagSez' => '',
                'objSez' => $objSez,
                'isBuy' => $isBuy,
                'aCommodity' => $aCommodity,
            ]);
        } else {
            Yii::$app->getSession()->addFlash(
                'error',
                'Invalid Request.'
            );
            return $this->redirect(['user/policy']);
        }
    }

    public function actionCheckOdc()
    {
        if (Yii::$app->request->isAjax) {
            $aRequest = Yii::$app->request->post();
            $commodity = !empty($aRequest['commodity']) ? $aRequest['commodity'] : '';
            $objCommodity =  \common\models\Commodity::find()
                ->where(['code' => $commodity])->one();
            $flag = false;
            if ($objCommodity->is_odc == 1) {
                $flag = true;
            }
 
            $flag = true;
            \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
            return [
                'isOdc' => $flag,
            ];
        }
    }

    private function insertPermiumCalculation($commodity,$cargoModel,
            $transitType,$transitMode,$objBajajGroup,$countryType='')
    {
        $transitTypeModel = new \common\models\TransitType();
        $transitTypeId = $transitTypeModel->getIdByTransitType($cargoModel->transit_type);
        $transitModeModel = new \common\models\TransitMode();
        $transitModeId = $transitModeModel->getIdByTransitMode($cargoModel->transit_mode);
        $objState = \common\models\DgnoteTrailerState::find()
                ->where(['name' => $cargoModel->billing_state])->one();
        $w2w = ($cargoModel->w2w=='Y') ? 1: 0; 
        $aPremium = $this->premiumCalculation( $transitTypeId,
                $transitModeId,
                2,
                $cargoModel->commodity,
                $cargoModel->sum_insured,
                $movement = 'S',
                $cargoModel->w2w,
                $gstin = '',
                $objState->state_code,
                $cargoModel->coverage,
                $billingType = ''
            );
        $cargoModel->service_tax_amount = str_replace(',', '', $aPremium['service_tax_amount']);
        $cargoModel->stamp_duty_amount = $aPremium['stamp_duty_amount'];
        $cargoModel->total_premium = str_replace(',', '', $aPremium['total_premium']);
        $cargoModel->premium = $aPremium['premium'];
        
        $cargoModel->bajaj_group = isset($objBajajGroup) ? (isset($objBajajGroup->masterPolicy) ? $objBajajGroup->masterPolicy->bajaj_group : '' ) : '';
        
        $cargoModel->institution_name = 'DgNote A/c '.$cargoModel->institution_name; 
        $commodityModel = new \common\models\Commodity(); 
        $commodityId = $commodityModel->getIdByCommodity($cargoModel->commodity);
        $cargoModel->dgnote_commission = $this->getDgnoteRate($commodityId);
        $cargoModel->service_tax_attributes = 
                \yii::$app->gst->getSeralizedGSTWithOutRound($cargoModel->premium,$cargoModel->total_premium,$objState->state_code);
        $cargoModel->valuation_basis = $this->getBov($transitTypeId,$cargoModel->commodity);
        $cargoModel->is_uploaded = 1;
        $cargoModel->upload_file_status = 'success';
        $cargoModel->offline_status = 'success';
        $cargoModel->update(FALSE,['service_tax_amount','stamp_duty_amount','total_premium',
            'premium','dgnote_commission','service_tax_attributes',
            'valuation_basis','institution_name','bajaj_group','is_uploaded',
            'upload_file_status','offline_status']);
    }

    private function getBajaGroup($commodit,$companyId)
    {
        $commodityModel = new \common\models\Commodity();
        $commodityId = $commodityModel->getIdByCommodity($commodit);
        $objGroup = \common\models\CompanyPolicyCargoCertificate::
                            checkBajajRateGroupForPolicy($commodityId, $companyId);
        if(!$objGroup){
            return false;
        } else{
            return $objGroup;
        }
    }
}
