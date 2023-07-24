<?php

namespace App\Http\Controllers;

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Order;
use App\Models\Plan;
use App\Models\UserCoupon;
use App\Models\Utility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class MolliePaymentController extends Controller
{

    public $api_key;
    public $profile_id;
    public $partner_id;
    public $is_enabled;
    protected $invoiceData;

    public function paymentConfig()
    {
        $payment_setting = Utility::getCompanyPaymentSetting($this->invoiceData->created_by);

        $this->api_key    = isset($payment_setting['mollie_api_key']) ? $payment_setting['mollie_api_key'] : '';
        $this->profile_id = isset($payment_setting['mollie_profile_id']) ? $payment_setting['mollie_profile_id'] : '';
        $this->partner_id = isset($payment_setting['mollie_partner_id']) ? $payment_setting['mollie_partner_id'] : '';
        $this->is_enabled = isset($payment_setting['is_mollie_enabled']) ? $payment_setting['is_mollie_enabled'] : 'off';

        return $this;
    }


    public function customerPayWithMollie(Request $request)
    {
        $invoiceID = \Illuminate\Support\Facades\Crypt::decrypt($request->invoice_id);
        $invoice   = Invoice::find($invoiceID);
        $this->invoiceData = $invoice;

        $payment   = $this->paymentConfig();


        if($invoice)
        {
            $price = $request->amount;
            if($price > 0)
            {
                $mollie = new \Mollie\Api\MollieApiClient();
                $mollie->setApiKey($this->api_key);

                $payment = $mollie->payments->create(
                    [
                        "amount" => [
                            "currency" => Utility::getValByName('site_currency'),
                            "value" => number_format($price, 2),
                        ],
                        "description" => "payment for product",
                        "redirectUrl" => route(
                            'customer.mollie', [
                                                $request->invoice_id,
                                                $price,
                                            ]
                        ),
                    ]
                );

                session()->put('mollie_payment_id', $payment->id);

                return redirect($payment->getCheckoutUrl())->with('payment_id', $payment->id);

            }
            else
            {
                $res['msg']  = __("Enter valid amount.");
                $res['flag'] = 2;

                return $res;
            }


        }
        else
        {
            return redirect()->back()->with('error', 'Invoice is deleted.');
        }

    }

    public function getInvoicePaymentStatus(Request $request, $invoice_id, $amount)
    {

        $invoiceID = \Illuminate\Support\Facades\Crypt::decrypt($invoice_id);
        $invoice   = Invoice::find($invoiceID);

        $this->invoiceData = $invoice;
        $payment = $this->paymentConfig();

        $orderID  = strtoupper(str_replace('.', '', uniqid('', true)));
        $settings = DB::table('settings')->where('created_by', '=', $invoice->created_by)->get()->pluck('value', 'name');


        $result    = array();

        if($invoice)
        {
            try
            {
                $mollie = new \Mollie\Api\MollieApiClient();
                $mollie->setApiKey($this->api_key);

                if(session()->has('mollie_payment_id'))
                {
                    $payment = $mollie->payments->get(session()->get('mollie_payment_id'));

                    if($payment->isPaid())
                    {


                        $payments = InvoicePayment::create(
                            [
                                'invoice_id' => $invoice->id,
                                'date' => date('Y-m-d'),
                                'amount' => $request->amount,
                                'payment_method' => 1,
                                'order_id' => $orderID,
                                'payment_type' => __('Mollie'),
                                'receipt' => '',
                                'description' => __('Invoice') . ' ' . Utility::invoiceNumberFormat($settings, $invoice->invoice_id),
                            ]
                        );

                        $invoice = Invoice::find($invoice->id);


                        if($invoice->getDue() <= 0)
                        {
                            Invoice::change_status($invoice->id, 4);
                        }
                        else
                        {
                            Invoice::change_status($invoice->id, 3);
                        }

                        //Slack Notification
                        $setting  = Utility::settings($invoice->created_by);
                        $customer = Customer::find($invoice->customer_id);
                        if(isset($setting['payment_notification']) && $setting['payment_notification'] == 1)
                        {
                            $msg = __("New payment of").' ' . $request->amount . __("created for").' ' . $customer->name . __("by").' '. __('Mollie'). '.';
                            Utility::send_slack_msg($msg,$invoice->created_by);
                        }

                        //Telegram Notification
                        $setting  = Utility::settings($invoice->created_by);
                        $customer = Customer::find($invoice->customer_id);
                        if(isset($setting['telegram_payment_notification']) && $setting['telegram_payment_notification'] == 1)
                        {
                            $msg = __("New payment of").' ' . $request->amount . __("created for").' ' . $customer->name . __("by").' '. __('Mollie'). '.';
                            Utility::send_telegram_msg($msg ,$invoice->created_by);
                        }

                        //Twilio Notification
                        $setting  = Utility::settings($invoice->created_by);
                        $customer = Customer::find($invoice->customer_id);
                        if(isset($setting['twilio_payment_notification']) && $setting['twilio_payment_notification'] ==1)
                        {
                            $msg = __("New payment of").' ' . $amount . __("created for").' ' . $customer->name . __("by").' '.  $payments['payment_type'] . '.';
                            Utility::send_twilio_msg($customer->contact,$msg,$invoice->created_by);
                        }


                        return redirect()->route('invoice.link.copy', Crypt::encrypt($invoice->id))->with('success', __(' Payment successfully added.'));

                    }
                    else
                    {
                        return redirect()->route('invoice.link.copy', Crypt::encrypt($invoice->id))->with('error', __('Transaction has been failed! '));
                    }
                }
                else
                {
                    return redirect()->route('invoice.link.copy', Crypt::encrypt($invoice->id))->with('error', __('Transaction has been failed! '));
                }
            }
            catch(\Exception $e)
            {
                return redirect()->route('invoice.link.copy', Crypt::encrypt($invoice->id))->with('error', __('Invoice not found!'));
            }
        }
    }
}
