<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class CartController extends Controller
{
    /**
     * Lấy tồn kho khả dụng thực tế từ các lô còn hạn (tương thích thuật toán FEFO).
     */
    private function getAvailableStock(Product $product): int
    {
        if (method_exists($product, 'batches')) {
            return (int) $product->batches()
                ->where('available_quantity', '>', 0)
                ->where(function ($q) {
                    $q->whereNull('expires_at')
                      ->orWhere('expires_at', '>=', now());
                })
                ->sum('available_quantity');
        }

        return 0;
    }

    /**
     * Tính toán tổng số lượng và tổng tiền giỏ hàng.
     */
    private function buildCartSummary(array $cart): array
    {
        $totalQuantity = 0;
        $totalAmount = 0;

        foreach ($cart as $item) {
            $totalQuantity += $item['quantity'];
            $totalAmount += $item['price'] * $item['quantity'];
        }

        return [
            'items' => $cart,
            'total_quantity' => $totalQuantity,
            'total_amount' => $totalAmount,
            'formatted_total_amount' => number_format($totalAmount, 0, ',', '.') . 'đ',
            'is_empty' => empty($cart),
        ];
    }

    /**
     * Hiển thị trang giỏ hàng (GET /cart)
     */
    public function index()
    {
        $cart = session()->get('cart', []);
        return view('cart.index', compact('cart'));
    }

    /**
     * API: Thêm sản phẩm vào giỏ hàng (POST /cart/add)
     */
    public function add(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'product_id' => ['required', 'integer', 'exists:products,id'],
                'quantity'   => ['nullable', 'integer', 'min:1'],
            ], [
                'product_id.required' => 'Thiếu thông tin sản phẩm.',
                'product_id.exists'   => 'Sản phẩm không tồn tại.',
                'quantity.min'        => 'Số lượng phải lớn hơn hoặc bằng 1.',
            ]);

            $productId = (int) $validated['product_id'];
            $addQty    = (int) ($validated['quantity'] ?? 1);

            $product = Product::findOrFail($productId);

            if (isset($product->is_active) && !$product->is_active) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sản phẩm hiện ngừng kinh doanh.',
                ], 400);
            }

            $availableStock = $this->getAvailableStock($product);

            if ($availableStock <= 0) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sản phẩm đã hết hàng.',
                ], 400);
            }

            $cart = session()->get('cart', []);
            $currentQtyInCart = isset($cart[$productId]) ? (int) $cart[$productId]['quantity'] : 0;
            $newQty = $currentQtyInCart + $addQty;

            if ($newQty > $availableStock) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Số lượng vượt quá tồn kho (chỉ còn {$availableStock} {$product->unit}).",
                    'data'    => ['available_stock' => $availableStock],
                ], 422);
            }

            $price = (float) $product->base_price;

            if (isset($cart[$productId])) {
                $cart[$productId]['quantity'] = $newQty;
                $cart[$productId]['price']    = $price;
                $cart[$productId]['stock']    = $availableStock;
            } else {
                $cart[$productId] = [
                    'product_id' => $product->id,
                    'name'       => $product->name,
                    'slug'       => $product->slug ?? '',
                    'image'      => $product->image_path ?? '',
                    'unit'       => $product->unit ?? 'sp',
                    'price'      => $price,
                    'quantity'   => $newQty,
                    'stock'      => $availableStock,
                ];
            }

            session()->put('cart', $cart);
            $summary = $this->buildCartSummary($cart);

            return response()->json([
                'status'  => 'success',
                'message' => 'Thêm sản phẩm thành công.',
                'data'    => [
                    'item'                   => $cart[$productId],
                    'subtotal'               => number_format($price * $newQty, 0, ',', '.') . 'đ',
                    'total_quantity'         => $summary['total_quantity'],
                    'total_amount'           => $summary['total_amount'],
                    'formatted_total_amount' => $summary['formatted_total_amount'],
                ],
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => collect($e->errors())->flatten()->first(),
                'errors'  => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            Log::error('Cart Add Error: ' . $e->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => 'Có lỗi hệ thống xảy ra, vui lòng thử lại sau.',
            ], 500);
        }
    }

    /**
     * API: Cập nhật số lượng sản phẩm trong giỏ (PATCH /cart/update/{productId})
     */
    public function update(Request $request, int $productId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'quantity' => ['required', 'integer', 'min:1'],
            ], [
                'quantity.required' => 'Vui lòng nhập số lượng.',
                'quantity.min'      => 'Số lượng tối thiểu là 1.',
            ]);

            $newQty = (int) $validated['quantity'];
            $cart = session()->get('cart', []);

            if (!isset($cart[$productId])) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sản phẩm không có trong giỏ hàng.',
                ], 404);
            }

            $product = Product::findOrFail($productId);
            $availableStock = $this->getAvailableStock($product);

            if ($availableStock <= 0) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sản phẩm đã hết hàng.',
                ], 400);
            }

            if ($newQty > $availableStock) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Số lượng vượt quá tồn kho (tối đa {$availableStock} {$product->unit}).",
                    'data'    => [
                        'current_quantity' => $cart[$productId]['quantity'],
                        'available_stock'  => $availableStock,
                    ],
                ], 422);
            }

            $price = (float) $product->base_price;
            $cart[$productId]['quantity'] = $newQty;
            $cart[$productId]['price']    = $price;
            $cart[$productId]['stock']    = $availableStock;

            session()->put('cart', $cart);
            $summary = $this->buildCartSummary($cart);

            return response()->json([
                'status'  => 'success',
                'message' => 'Cập nhật giỏ hàng thành công.',
                'data'    => [
                    'product_id'              => $productId,
                    'quantity'                => $newQty,
                    'available_stock'         => $availableStock,
                    'item_subtotal'           => $price * $newQty,
                    'formatted_item_subtotal' => number_format($price * $newQty, 0, ',', '.') . 'đ',
                    'total_quantity'          => $summary['total_quantity'],
                    'total_amount'            => $summary['total_amount'],
                    'formatted_total_amount'  => $summary['formatted_total_amount'],
                ],
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => collect($e->errors())->flatten()->first(),
            ], 422);
        } catch (Throwable $e) {
            Log::error('Cart Update Error: ' . $e->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => 'Có lỗi khi cập nhật giỏ hàng.',
            ], 500);
        }
    }

    /**
     * API: Xóa sản phẩm khỏi giỏ hàng (DELETE /cart/remove/{productId})
     */
    public function remove(int $productId): JsonResponse
    {
        try {
            $cart = session()->get('cart', []);

            if (!isset($cart[$productId])) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sản phẩm không tồn tại trong giỏ hàng.',
                ], 404);
            }

            unset($cart[$productId]);
            session()->put('cart', $cart);
            $summary = $this->buildCartSummary($cart);

            return response()->json([
                'status'  => 'success',
                'message' => 'Xóa sản phẩm thành công.',
                'data'    => [
                    'removed_product_id'     => $productId,
                    'is_empty'               => $summary['is_empty'],
                    'total_quantity'         => $summary['total_quantity'],
                    'total_amount'           => $summary['total_amount'],
                    'formatted_total_amount' => $summary['formatted_total_amount'],
                ],
            ], 200);

        } catch (Throwable $e) {
            Log::error('Cart Remove Error: ' . $e->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => 'Không thể xóa sản phẩm lúc này.',
            ], 500);
        }
    }
}
