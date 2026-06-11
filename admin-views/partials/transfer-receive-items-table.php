<?php
/**
 * Shared table for internal import / internal return receive screens.
 *
 * @package tgs-transfer-management
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('tgs_transfer_render_receive_items_card')) {
    function tgs_transfer_render_receive_items_card(array $args)
    {
        $args = wp_parse_args($args, [
            'row_id' => '',
            'card_id' => '',
            'title' => 'Sản phẩm',
            'count_id' => '',
            'count_text' => '0 sản phẩm',
            'badge_class' => 'bg-secondary',
            'header_class' => '',
            'table_id' => '',
            'tbody_id' => '',
            'tfoot_id' => '',
            'foot_prefix' => 'foot',
            'quantity_label' => 'SL nhập',
            'loading_text' => 'Đang tải sản phẩm...',
            'footer_label' => 'Tổng cộng:',
        ]);

        $foot_prefix = (string) $args['foot_prefix'];
        ?>
        <div class="row mb-4" <?php echo $args['row_id'] ? 'id="' . esc_attr($args['row_id']) . '"' : ''; ?>>
            <div class="col-12">
                <div class="card" <?php echo $args['card_id'] ? 'id="' . esc_attr($args['card_id']) . '"' : ''; ?>>
                    <div class="card-header d-flex justify-content-between align-items-center <?php echo esc_attr($args['header_class']); ?>">
                        <h5 class="card-title mb-0"><?php echo esc_html($args['title']); ?></h5>
                        <span class="badge <?php echo esc_attr($args['badge_class']); ?>" id="<?php echo esc_attr($args['count_id']); ?>">
                            <?php echo esc_html($args['count_text']); ?>
                        </span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" id="<?php echo esc_attr($args['table_id']); ?>" style="min-width: 1650px;">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 3%;">#</th>
                                        <th style="width: 13%;">Sản phẩm</th>
                                        <th style="width: 7%;">SKU</th>
                                        <th style="width: 5%;">SL tối đa</th>
                                        <th style="width: 8%;">Mã định danh</th>
                                        <th style="width: 9%;">ĐVT quy đổi</th>
                                        <th style="width: 6%;">SL ĐVT</th>
                                        <th style="width: 5%;"><?php echo esc_html($args['quantity_label']); ?></th>
                                        <th style="width: 5%;" title="Số lượng từ chứng từ gốc">SL CT</th>
                                        <th style="width: 7%;">Khối lượng dự kiến</th>
                                        <th style="width: 7%;">Đơn giá</th>
                                        <th style="width: 7%;">TT không VAT</th>
                                        <th style="width: 4%;">CK(%)</th>
                                        <th style="width: 4%;">Thuế %</th>
                                        <th style="width: 7%;">Thuế VNĐ</th>
                                        <th style="width: 7%;">Thành tiền</th>
                                        <th style="width: 8%;">Ghi chú SP</th>
                                        <th style="width: 6%;">Trạng thái</th>
                                    </tr>
                                </thead>
                                <tbody id="<?php echo esc_attr($args['tbody_id']); ?>">
                                    <tr>
                                        <td colspan="18" class="text-center py-4 text-muted">
                                            <?php echo esc_html($args['loading_text']); ?>
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot class="table-light" id="<?php echo esc_attr($args['tfoot_id']); ?>" style="display: none;">
                                    <tr>
                                        <td colspan="5" class="text-end fw-semibold"><?php echo esc_html($args['footer_label']); ?></td>
                                        <td></td>
                                        <td class="fw-semibold" id="<?php echo esc_attr($foot_prefix); ?>TotalUnitQty">0</td>
                                        <td class="fw-semibold" id="<?php echo esc_attr($foot_prefix); ?>TotalImport">0</td>
                                        <td></td>
                                        <td class="fw-semibold text-info" id="<?php echo esc_attr($foot_prefix); ?>TotalWeight">—</td>
                                        <td></td>
                                        <td class="fw-semibold" id="<?php echo esc_attr($foot_prefix); ?>TotalNoVat">0 đ</td>
                                        <td></td>
                                        <td></td>
                                        <td class="fw-semibold text-danger" id="<?php echo esc_attr($foot_prefix); ?>TotalTax">0 đ</td>
                                        <td class="fw-semibold text-primary" id="<?php echo esc_attr($foot_prefix); ?>TotalAmount">0 đ</td>
                                        <td></td>
                                        <td id="<?php echo esc_attr($foot_prefix); ?>Status">—</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
