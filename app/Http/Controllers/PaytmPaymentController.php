<?php

namespace App\Http\Controllers;

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Models\Utility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PaytmWallet;

class PaytmPaymentController extends Controller
{
    protected $invoiceData;

    public function paymentConfig()
    {

            $payment_setting = Utility::getCompanyPaymentSetting($this->invoiceData->created_by);



        config(
            [
                'services.paytm-wallet.env' => isset($payment_setting['paytm_mode']) ? $payment_setting['paytm_mode'] : '',
                'services.paytm-wallet.merchant_id' => isset($payment_setting['paytm_merchant_id']) ? $payment_setting['paytm_merchant_id'] : '',
                'services.paytm-wallet.merchant_key' => isset($payment_setting['paytm_merchant_key']) ? $payment_setting['paytm_merchant_key'] : '',
                'services.paytm-wallet.merchant_website' => 'WEBSTAGING',
                'services.paytm-wallet.channel' => 'WEB',
                'services.paytm-wallet.industry_type' => isset($payment_setting['paytm_industry_type']) ? $payment_setting['paytm_industry_type'] : '',
            ]
        );
        return $payment_setting;
    }




    public function customerPayWithPaytm(Request $request)
    {

        $invoiceID = \Illuminate\Support\Facades\Crypt::decrypt($request->invoice_id);
        $invoice   = Invoice::find($invoiceID);
        $this->invoiceData = $invoice;
        $user      = User::find($invoice->created_by);

        $payment   = $this->paymentConfig();

        if($invoice)
        {
            $price = $request->amount;
            if($price > 0)
            {
                $call_back = route(
                    'customer.paytm', [
                                       $request->invoice_id,
                                       $price
                                   ]
                );
                $payment   = PaytmWallet::with('receive');

                $payment->prepare(
                    [
                        'order' => date('Y-m-d') . '-' . strtotime(date('Y-m-d H:i:s')),
                        'user' => $user->id,
                        'mobile_number' => $request->mobile,
                        'email' => $user->email,
                        'amount' => $price,
                        'invoice' => $invoice->id,
                        'callback_url' => $call_back,
                    ]
                );


                return $payment->receive();

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
            return Utility::error_res(__('Invoice is deleted.'));
        }

    }

    public function getInvoicePaymentStatus(Request $request, $invoice_id,$amount)
    {


        $invoiceID = \Illuminate\Support\Facades\Crypt::decrypt($invoice_id);
        $invoice   = Invoice::find($invoiceID);

        $orderID  = strtoupper(str_replace('.', '', uniqid('', true)));
        $settings = DB::table('settings')->where('created_by', '=',$invoice->created_by)->get()->pluck('value', 'name');

        $this->invoiceData = $invoice;
        $payment  = $this->paymentConfig();

        if($invoice)
        {
            try
            {
                $transaction = PaytmWallet::with('receive');

                $response    = $transaction->response();

                if($transaction->isSuccessful())
                {

                    $payments = InvoicePayment::create(
                        [
                            'invoice_id' => $invoice->id,
                            'date' => date('Y-m-d'),
                            'amount' => $request->amount,
                            'payment_method' => 1,
                            'order_id' => $orderID,
                            'payment_type' => __('Paytm'),
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
                        $msg = __("New payment of").' ' . $request->amount . __("created for").' ' . $customer->name . __("by").' '. __('Paytm'). '.';
                        Utility::send_slack_msg($msg,$invoice->created_by);
                    }

                    //Telegram Notification
                    $setting  = Utility::settings($invoice->created_by);
                    $customer = Customer::find($invoice->customer_id);
                    if(isset($setting['telegram_payment_notification']) && $setting['telegram_payment_notification'] == 1)
                    {
                        $msg = __("New payment of").' ' . $request->amount . __("created for").' ' . $customer->name . __("by").' '. __('Paytm'). '.';
                        Utility::send_telegram_msg($msg,$invoice->created_by);
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
            catch(\Exception $e)
            {
                dd($e);
                return redirect()->route('invoice.link.copy', Crypt::encrypt($invoice->id))->with('error', __('Invoice not found!'));
            }
        }
    }
}
