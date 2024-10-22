<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
class PaymentController extends Controller
{
    public function storeFromCart(Request $request)
{
    $validated = $request->validate([
        'payment_method' => 'required|string|in:cod,momo,vnpay', 
        'name' => 'required|string|max:255',
        'address' => 'required|string|max:255',
        'phone' => ['required', 'regex:/^(0|\+84)[0-9]{9}$/'],
        'price' => 'required|integer|min:1',
    ], [
        'phone.required' => 'Vui lòng nhập số điện thoại.',
        'phone.regex' => 'Số điện thoại không hợp lệ. Vui lòng nhập số điện thoại hợp lệ (ví dụ: 0123456789 hoặc +840123456789).',
    ]);
    
    // Tạo một đơn hàng mới
    $order = Order::create([
        'user_id' => Auth::id(),    
        'name' => $validated['name'],
        'address' => $validated['address'],
        'phone' => $validated['phone'],
        'payment_method' => $validated['payment_method'],
        'total_price' => $validated['price'],
        'status' => $validated['payment_method'] === 'momo' || $validated['payment_method'] === 'vnpay' ? 'pending' : 'pending',
    ]);

    // Nếu phương thức thanh toán là MoMo
    if ($validated['payment_method'] === 'momo') {
        // Tạo bản ghi thanh toán cho MoMo
        $payment = new Payment();
        $payment->order_id = $order->id;
        $payment->payment_method = 'momo';
        $payment->price = $validated['price'];
        $payment->save();

        return $this->createMomoTransaction($order);
    }

    // Nếu phương thức thanh toán là VNPay
    if ($validated['payment_method'] === 'vnpay') {
        // Tạo bản ghi thanh toán cho VNPay
        $payment = new Payment();
        $payment->order_id = $order->id;
        $payment->payment_method = 'vnpay';
        $payment->price = $validated['price'];
        $payment->save();

        return $this->createVNPayTransaction($order); // Gọi hàm xử lý VNPay
    }

    // Tạo bản ghi thanh toán cho COD
    $payment = new Payment();
    $payment->order_id = $order->id;
    $payment->payment_method = 'cod';
    $payment->price = $validated['price']; 
    $payment->save();

    // Gán sản phẩm từ giỏ hàng vào đơn hàng
    $this->attachProductsToOrder($order);

    // Xóa giỏ hàng sau khi thanh toán
    $this->clearCart();

    return redirect()->route('customer.thank_you')->with('success', 'Đặt hàng thành công!');
}

    
    public function thankYou(Request $request)
    {
        return view('customer.thank_you');
    }
    

    // Tạo giao dịch MoMo
    private function createMomoTransaction(Order $order)
    {
        $endpoint = "https://test-payment.momo.vn/v2/gateway/api/create";

        $partnerCode = 'MOMOBKUN20180529';
        $accessKey = 'klm05TvNBzhg7h7j';
        $secretKey = 'at67qH6mk8w5Y1nAyMoYKMWACiEi2bsa';
        $orderInfo = "Thanh toán qua MoMo";
        $amount = $order->total_price; // Lấy giá trị thanh toán từ đơn hàng
        $orderId =  $orderId = $this->generateNewOrderId($order);// Mã đơn hàng
        $redirectUrl = route('customer.thank_you_momo');
        $ipnUrl = route('customer.thank_you_momo');
        $extraData = "";

        $requestId = time() . "";
        $requestType = "payWithATM";
        $rawHash = "accessKey=" . $accessKey . "&amount=" . $amount . "&extraData=" . $extraData . "&ipnUrl=" . $ipnUrl . "&orderId=" . $orderId . "&orderInfo=" . $orderInfo . "&partnerCode=" . $partnerCode . "&redirectUrl=" . $redirectUrl . "&requestId=" . $requestId . "&requestType=" . $requestType;
        $signature = hash_hmac("sha256", $rawHash, $secretKey);

        // Chuẩn bị dữ liệu gửi đi
        $data = [
            'partnerCode' => $partnerCode,
            'partnerName' => "Test",
            "storeId" => "MomoTestStore",
            'requestId' => $requestId,
            'amount' => $amount,
            'orderId' => $orderId,
            'orderInfo' => $orderInfo,
            'redirectUrl' => $redirectUrl,
            'ipnUrl' => $ipnUrl,
            'lang' => 'vi',
            'extraData' => $extraData,
            'requestType' => $requestType,
            'signature' => $signature
        ];

        // Gửi yêu cầu qua POST bằng CURL
        $result = $this->execPostRequest($endpoint, json_encode($data));
        $jsonResult = json_decode($result, true);
        Log::info('MoMo Payment Response: ', ['response' => $result]);


          // Gán sản phẩm từ giỏ hàng vào đơn hàng
          $this->attachProductsToOrder($order);
          // Xóa giỏ hàng sau khi thanh toán
          $this->clearCart(); // Giả sử phương thức này xóa giỏ hàng
        // Kiểm tra và điều hướng đến URL thanh toán của MoMo
        if (isset($jsonResult['payUrl'])) {
            return redirect($jsonResult['payUrl']); // Chuyển hướng người dùng đến URL thanh toán
        }

        return redirect()->route('customer.orders.index')->with('error', 'Đã xảy ra lỗi trong quá trình thanh toán với MoMo.');
    }

    // Hàm sử dụng CURL để gửi request tới MoMo
    private function execPostRequest($url, $data)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($data)
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Tăng thời gian chờ nếu cần
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // Tăng thời gian kết nối nếu cần
    
        $result = curl_exec($ch);
    
        // Xử lý lỗi cURL
        if (curl_errno($ch)) {
            $errorMessage = curl_error($ch);
            // Ghi log hoặc xử lý thông báo lỗi
            Log::error("cURL Error: " . $errorMessage);
            return json_encode(['error' => 'Đã xảy ra lỗi khi kết nối đến dịch vụ thanh toán.']);
        }
    
        curl_close($ch);
        return $result;
    }
    

    // Gán sản phẩm từ giỏ hàng vào đơn hàng
    protected function attachProductsToOrder(Order $order)
    {
        $carts = Cart::where('user_id', Auth::id())->get();

        foreach ($carts as $cart) {
            // Gán sản phẩm vào đơn hàng
            $order->products()->attach($cart->product_id, ['quantity' => $cart->quantity]);

            // Cập nhật số lượng sản phẩm trong kho
            $product = $cart->product;
            $product->quantity -= $cart->quantity; // Trừ số lượng sản phẩm trong kho
            $product->save();
        }
    }

    // Hiển thị form thanh toán
    public function showPaymentForm(Request $request)
    {
        // Lấy tất cả các sản phẩm trong giỏ hàng của người dùng
        $carts = Cart::where('user_id', Auth::id())->get();
    
        // Tính tổng giá của tất cả các sản phẩm trong giỏ hàng
        $total_price = $carts->sum(function ($cart) {
            return $cart->product->price * $cart->quantity;
        });
    
        // Trả về view thanh toán với tất cả sản phẩm trong giỏ hàng và tổng giá
        return view('customer.orders.payment', compact('carts', 'total_price'));
    }
  
    // Phương thức để xóa giỏ hàng
    protected function clearCart()
    {
        $userId = Auth::id();
        if ($userId) {
            Cart::where('user_id', $userId)->delete();
        }
    }
    public function handleMomoNotify(Request $request)
    {
        // Ghi log thông tin nhận được
    
        // Kiểm tra kết quả giao dịch
        $resultCode = $request->input('resultCode'); // Lấy mã kết quả từ request
        $orderId = $request->input('orderId'); // Lấy ID đơn hàng từ request
    

        // Kiểm tra nếu giao dịch thành công
        if ($resultCode === '0') {
            // Cập nhật trạng thái đơn hàng
            $order = Order::find($orderId); // Tìm đơn hàng theo ID
            if ($order) {
                $order->status = 'paid'; // Cập nhật trạng thái
                $order->save(); // Lưu thay đổi
            }
            
            // Trả về view cảm ơn
            return view('customer.thank_you_momo')->with('success', 'Thanh toán thành công!');
        }
      // Tạo thông báo lỗi chi tiết từ thông tin trả về
      $message = $request->input('message', 'Không có thông tin cụ thể về lỗi.');

      // Trả về thông báo lỗi cho người dùng
      return redirect()->route('customer.orders.index')->with('error', 'Thanh toán không thành công: ' . $message);
    }
    
    public function retryPayment($id)
{
    $order = Order::find($id);

    if (!$order || $order->status !== 'pending') {
        return redirect()->route('customer.orders.index')->with('error', 'Đơn hàng không hợp lệ hoặc đã thanh toán.');
    }
    $newOrderId = $this->generateNewOrderId($order); // Tạo orderId mới

    // Tạo giao dịch MoMo mới với orderId mới

    // Xử lý theo từng phương thức thanh toán
    if ($order->payment_method === 'momo') {
        return $this->createMomoTransactionWithNewId($order, $newOrderId);
    } elseif ($order->payment_method === 'vnpay') {
        return $this->createVNPayTransaction($order); 
    }

    return redirect()->route('customer.orders.index')->with('error', 'Phương thức thanh toán không được hỗ trợ.');
}

    

public function createVNPayTransaction(Order $order)
{
    $vnp_TmnCode = "8JAHP2J1"; // Mã website của bạn từ VNPay
    $vnp_HashSecret = "M6C9BSSSSUHYAP1PIOZ3W15NXIMPIA2C"; // Chuỗi bí mật để mã hóa dữ liệu
    $vnp_Url = "https://sandbox.vnpayment.vn/paymentv2/vpcpay.html"; // URL sandbox của VNPay
    $vnp_Returnurl = route('customer.thank_you_vnpay');
    $vnp_TxnRef = $order->id; // Mã đơn hàng (ID từ hệ thống)
    $vnp_OrderInfo = "Thanh toán đơn hàng " . $order->id;
    $vnp_Amount = $order->total_price * 100;
    
    $vnp_Locale = 'vn';
    $vnp_IpAddr = request()->ip();

    $inputData = array(
        "vnp_Version" => "2.1.0",
        "vnp_TmnCode" => $vnp_TmnCode,
        "vnp_Amount" => $vnp_Amount,
        "vnp_Command" => "pay",
        "vnp_CreateDate" => now()->format('YmdHis'),
        "vnp_CurrCode" => "VND",
        "vnp_IpAddr" => $vnp_IpAddr,
        "vnp_Locale" => $vnp_Locale,
        "vnp_OrderInfo" => $vnp_OrderInfo,
        "vnp_ReturnUrl" => $vnp_Returnurl,
        "vnp_TxnRef" => $vnp_TxnRef
    );

    // Log đầu vào của giao dịch VNPay
    Log::info('VNPay Transaction Input:', $inputData);

    ksort($inputData);
    $query = '';
    $hashdata = '';
    foreach ($inputData as $key => $value) {
        $hashdata .= urlencode($key) . "=" . urlencode($value) . '&';
        $query .= urlencode($key) . "=" . urlencode($value) . '&';
    }

    // Bỏ đi ký tự '&' cuối cùng
    $hashdata = rtrim($hashdata, '&');
    $query = rtrim($query, '&');

    // Log dữ liệu trước khi mã hóa
    Log::info('VNPay Hash Data before Secure Hash calculation:', [$hashdata]);

    // Tạo Secure Hash
    if (isset($vnp_HashSecret)) {
        $vnpSecureHash = hash_hmac('sha512', $hashdata, $vnp_HashSecret); 
        Log::info('VNPay Secure Hash:', [$vnpSecureHash]);
        $query .= '&vnp_SecureHash=' . $vnpSecureHash;
    }

    // Tạo URL chuyển hướng
    $vnp_Url .= "?" . $query;

    // Log URL chuyển hướng
    Log::info('VNPay Redirect URL:', [$vnp_Url]);

    // Chuyển hướng người dùng đến trang thanh toán VNPay
    return redirect($vnp_Url);
}


    
public function handleVNPayCallback(Request $request)
{
    $vnp_SecureHash = $request->input('vnp_SecureHash');
    $inputData = $request->except('vnp_SecureHash');
    
    ksort($inputData);
    $hashData = '';
    foreach ($inputData as $key => $value) {
        $hashData .= $key . '=' . $value . '&';
    }
    $hashData = rtrim($hashData, '&');

    $secureHash = hash_hmac('sha512', $hashData, 'VNPAY_SECRET');
    
    if ($secureHash === $vnp_SecureHash) {
        if ($request->input('vnp_ResponseCode') === '00') {
            $order = Order::find($request->input('vnp_TxnRef'));
            if ($order) {
                $order->status = 'paid';
                $order->save();
                return redirect()->route('customer.thank_you_vnpay')->with('success', 'Thanh toán thành công!');
            }
        }
    }

    return redirect()->route('customer.orders.index')->with('error', 'Thanh toán thất bại.');
}


public function thankYouVNPay(Request $request)
{
    $orderId = $request->input('vnp_TxnRef');
    $vnp_ResponseCode = $request->input('vnp_ResponseCode');

    if ($vnp_ResponseCode == '00') {
        return view('customer.thank_you_vnpay', ['orderId' => $orderId])->with('success', 'Thanh toán thành công!');
    } else {
        return view('customer.thank_you_vnpay', ['orderId' => $orderId])->with('error', 'Giao dịch không thành công!');
    }
}
private function createMomoTransactionWithNewId(Order $order, $newOrderId)
{
    $endpoint = "https://test-payment.momo.vn/v2/gateway/api/create";

    $partnerCode = 'MOMOBKUN20180529';
    $accessKey = 'klm05TvNBzhg7h7j';
    $secretKey = 'at67qH6mk8w5Y1nAyMoYKMWACiEi2bsa';
    $orderInfo = "Thanh toán qua MoMo";
    $amount = $order->total_price; // Lấy giá trị thanh toán từ đơn hàng
    $redirectUrl = route('customer.thank_you_momo');
    $ipnUrl = route('customer.thank_you_momo');
    $extraData = "";

    $requestId = time() . "";
    $requestType = "payWithATM";
    $rawHash = "accessKey=" . $accessKey . "&amount=" . $amount . "&extraData=" . $extraData . "&ipnUrl=" . $ipnUrl . "&orderId=" . $newOrderId . "&orderInfo=" . $orderInfo . "&partnerCode=" . $partnerCode . "&redirectUrl=" . $redirectUrl . "&requestId=" . $requestId . "&requestType=" . $requestType;
    $signature = hash_hmac("sha256", $rawHash, $secretKey);

    // Chuẩn bị dữ liệu gửi đi
    $data = [
        'partnerCode' => $partnerCode,
        'partnerName' => "Test",
        "storeId" => "MomoTestStore",
        'requestId' => $requestId,
        'amount' => $amount,
        'orderId' => $newOrderId, // Sử dụng orderId mới
        'orderInfo' => $orderInfo,
        'redirectUrl' => $redirectUrl,
        'ipnUrl' => $ipnUrl,
        'lang' => 'vi',
        'extraData' => $extraData,
        'requestType' => $requestType,
        'signature' => $signature
    ];

    // Gửi yêu cầu qua POST bằng CURL
    $result = $this->execPostRequest($endpoint, json_encode($data));
    $jsonResult = json_decode($result, true);
    Log::info('MoMo Payment Response: ', ['response' => $result]);

    // Gán sản phẩm từ giỏ hàng vào đơn hàng
    $this->attachProductsToOrder($order);
    // Xóa giỏ hàng sau khi thanh toán
    $this->clearCart(); // Giả sử phương thức này xóa giỏ hàng

    // Kiểm tra và điều hướng đến URL thanh toán của MoMo
    if (isset($jsonResult['payUrl'])) {
        return redirect($jsonResult['payUrl']); // Chuyển hướng người dùng đến URL thanh toán
    }

    return redirect()->route('customer.orders.index')->with('error', 'Đã xảy ra lỗi trong quá trình thanh toán với MoMo.');
}

// Hàm tạo orderId mới
private function generateNewOrderId(Order $order)
{
    return $order->id . '-' . time(); // Tạo orderId mới bằng cách thêm thời gian vào orderId cũ
}

}
