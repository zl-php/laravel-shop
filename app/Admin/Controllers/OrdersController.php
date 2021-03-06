<?php

namespace App\Admin\Controllers;

use App\Http\Requests\Admin\HandleRefundRequest;
use App\Models\CrowdfundingProduct;
use App\Models\Order;
use App\Exceptions\InvalidRequestException;
use App\Services\OrderService;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Grid;
use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Illuminate\Foundation\Validation\ValidatesRequests;

class OrdersController extends AdminController
{

    use ValidatesRequests;

    /**
     * Title for current resource.
     *
     * @var string
     */
    protected $title = '订单';

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new Order());

        // 只展示已支付的订单，并且默认按支付时间倒序排序
        $grid->model()->whereNotNull('paid_at')->orderBy('paid_at', 'DESC');
        $grid->column('id', __('编号'));
        $grid->column('no', __('订单流水号'));
        $grid->column('user.name', __('卖家'));
        $grid->column('total_amount', __('总金额'))->sortable();
        $grid->column('paid_at', __('支付时间'));
        $grid->column('ship_status', __('物流'))->display(
            function ($value) {
                return Order::$shipStatusMap[$value];
            }
        );
        $grid->column('refund_status', __('退款状态'))->display(
            function ($value) {
                return Order::$refundStatusMap[$value];
            }
        );
        // 禁用创建按钮，后台不需要创建订单
        $grid->disableCreateButton();
        $grid->actions(
            function ($actions) {
                // 禁用删除和编辑按钮
                $actions->disableDelete();
                $actions->disableEdit();
            }
        );
        $grid->tools(
            function ($tools) {
                // 禁用批量删除按钮
                $tools->batch(
                    function ($batch) {
                        $batch->disableDelete();
                    }
                );
            }
        );

        return $grid;
    }

    /**
     * 订单详情
     *
     * @param mixed $id
     * @param Content $content
     * @return Content
     */
    public function show($id, Content $content)
    {
        return $content
            ->header('查看订单')
            // body 方法可以接受 Laravel 的视图作为参数
            ->body(view('admin.orders.show', ['order' => Order::query()->find($id)]));
    }

    /**
     * 物流发货
     *
     * @param Order $order
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     * @throws InvalidRequestException
     * @throws \Illuminate\Validation\ValidationException
     */
    public function ship(Order $order, Request $request)
    {
        // 判断当前订单是否已支付
        if (!$order->paid_at) {
            throw new InvalidRequestException('该订单未付款');
        }
        // 判断当前订单发货状态是否为未发货
        if ($order->ship_status !== Order::SHIP_STATUS_PENDING) {
            throw new InvalidRequestException('该订单已发货');
        }
        // 众筹订单只有在众筹成功后发货
        if ($order->type === Order::TYPE_CROWDFUNDING && $order->items[0]->product->crowdfunding->status !== CrowdfundingProduct::STATUS_SUCCESS) {
            throw new InvalidRequestException('众筹订单只能在众筹成功后发货');
        }

        // Laravel 5.5 之后 validate 方法可以返回校验过的值
        $data = $this->validate(
            $request,
            [
                'express_company' => ['required'],
                'express_no' => ['required'],
            ],
            [],
            [
                'express_company' => '物流公司',
                'express_no' => '物流单号',
            ]
        );
        // 将订单发货状态改为已发货，并存入物流信息
        $order->update(
            [
                'ship_status' => Order::SHIP_STATUS_DELIVERED,
                // 我们在 Order 模型的 $casts 属性里指明了 ship_data 是一个数组
                // 因此这里可以直接把数组传过去
                'ship_data' => $data,
            ]
        );

        // 返回上一页
        return redirect()->back();
    }

    /**
     * 订单退款
     *
     * @param Order $order
     * @param HandleRefundRequest $request
     * @return Order
     * @throws InvalidRequestException
     */
    public function handleRefund(Order $order, HandleRefundRequest $request, OrderService $orderService)
    {
        // 判断订单状态是否正确
        if ($order->refund_status !== Order::REFUND_STATUS_APPLIED) {
            throw new InvalidRequestException('订单状态不正确');
        }
        // 是否同意退款
        if ($request->input('agree')) {
            // 清空拒绝退款理由
            $extra = $order->extra ?: [];
            unset($extra['refund_disagree_reason']);
            $order->update(
                [
                    'extra' => $extra,
                ]
            );

            // 调用退款逻辑
            $orderService->refundOrder($order);
        } else {
            // 将拒绝退款理由放到订单的 extra 字段中
            $extra = $order->extra ?: [];
            $extra['refund_disagree_reason'] = $request->input('reason');
            // 将订单的退款状态改为未退款
            $order->update(
                [
                    'refund_status' => Order::REFUND_STATUS_PENDING,
                    'extra' => $extra,
                ]
            );
        }

        return $order;
    }

}
