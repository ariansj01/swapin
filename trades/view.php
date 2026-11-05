<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/dashboard_layout.php';
require_once __DIR__ . '/../includes/multi_trade.php';

$user = require_auth();
$uid  = (int)$user['id'];

$tradeId = (int)($_GET['id'] ?? 0);
if (!$tradeId) {
    header('Location: ' . APP_URL . '/trades');
    exit;
}

function fetch_trade_room(int $tradeId, ?int $uid = null): ?array
{
    $sql = 'SELECT t.*, ua.name AS user_a_name, ub.name AS user_b_name,
                la.title AS listing_a_title, lb.title AS listing_b_title,
                la.estimated_value AS listing_a_val, lb.estimated_value AS listing_b_val,
                (SELECT filename FROM listing_images WHERE listing_id = la.id AND is_primary = 1 LIMIT 1) AS img_a,
                (SELECT filename FROM listing_images WHERE listing_id = lb.id AND is_primary = 1 LIMIT 1) AS img_b
         FROM trades t
         JOIN users ua ON ua.id = t.user_a_id
         JOIN users ub ON ub.id = t.user_b_id
         JOIN listings la ON la.id = t.listing_a_id
         LEFT JOIN listings lb ON lb.id = t.listing_b_id
         WHERE t.id = ?';
    $params = [$tradeId];
    if ($uid !== null) {
        $sql .= ' AND (t.user_a_id = ? OR t.user_b_id = ?)';
        $params[] = $uid;
        $params[] = $uid;
    }
    return DB::fetch($sql, $params) ?: null;
}

// اتاق مبادله چندنفره داخل همین صفحه (اتاق امن) نمایش داده می‌شود.
multi_trade_ensure_tables();
$multiRoom = multi_trade_room_for_seed_trade($tradeId);

// وضعیت مبادله چندنفره باید در هر بارگذاری صفحه (GET و POST) محاسبه شود
$mtPendingRequests = [];
$mtMyMember        = null;
$mtIsInvited       = false;
$mtActiveCount     = 0;
$mtMembersAll      = [];
$mtLocked          = false;
$mtRoomClosed      = false;
$mtTakenTargets    = [];
$mtStatus          = '';
$mtIAmActive       = false;
$mtBoard           = null;
$mtMessages        = [];
$mtMyTracks        = [];
$mtDeliverTrack    = null;
$mtReceiveTrack    = null;
$mtFeeByMember     = [];
$mtSignedByMember  = [];
$mtMyFeeTotal      = 0.0;
$mtMyFeePaid       = false;
$mtAllFeesPaid     = false;
$mtMyDiff          = 0.0;
$mtMyDiffPaid      = false;
$mtDiffSettled     = false;
$mtMySigned        = false;
$mtAllSigned       = false;
$mtMyShippingSet   = false;
$mtMyShipped       = false;
$mtMyReceived      = false;
$mtAllReceived     = false;
$mtMyRated         = false;
$mtCompleted       = false;

/**
 * محاسبه وضعیت اتاق مبادله چندنفره و متغیرهای مربوطه
 */
$calculateMtState = function() use (
    $multiRoom, $uid,
    &$mtStatus, &$mtMyMember, &$mtIsInvited, &$mtIAmActive, &$mtActiveCount,
    &$mtMembersAll, &$mtLocked, &$mtRoomClosed, &$mtMessages, &$mtPendingRequests,
    &$mtBoard, &$mtMyTracks, &$mtFeeByMember, &$mtSignedByMember, &$mtAllFeesPaid,
    &$mtAllSigned, &$mtDiffSettled, &$mtDeliverTrack, &$mtReceiveTrack,
    &$mtMyFeeTotal, &$mtMyFeePaid, &$mtMyDiff, &$mtMyDiffPaid, &$mtMySigned,
    &$mtMyShippingSet, &$mtMyShipped, &$mtMyReceived, &$mtMyRated, &$mtAllReceived,
    &$mtCompleted, &$mtTakenTargets
) {
    if (!$multiRoom) return;

    $roomId        = (int)$multiRoom['id'];
    $mtStatus      = (string)$multiRoom['status'];
    $mtMyMember    = multi_trade_member($roomId, $uid);
    $mtIsInvited   = (bool)($mtMyMember && $mtMyMember['status'] === 'invited');
    $mtIAmActive   = (bool)($mtMyMember && $mtMyMember['status'] === 'active');
    $mtActiveCount = multi_trade_active_count($roomId);
    $mtMembersAll  = multi_trade_members($roomId, false);
    $mtLocked      = in_array($mtStatus, ['active', 'completed'], true);
    $mtRoomClosed  = in_array($mtStatus, ['locked', 'active', 'completed'], true);
    $mtMessages    = multi_trade_messages($roomId);

    if ($mtMyMember && $mtStatus === 'forming') {
        $mtPendingRequests = multi_trade_join_requests_pending($roomId);
    }

    if ($mtLocked) {
        $mtBoard    = multi_trade_board($roomId);
        $mtMyTracks = array_values(array_filter($mtBoard['tracks'], function ($t) use ($uid) {
            return (int)$t['deliverer_user_id'] === $uid || (int)$t['receiver_user_id'] === $uid;
        }));

        $tracksByDeliverer = [];
        $tracksByReceiver  = [];
        $allReceived = true;
        foreach ($mtBoard['tracks'] as $t) {
            $tracksByDeliverer[(int)$t['deliverer_member_id']] = $t;
            $tracksByReceiver[(int)$t['receiver_member_id']]   = $t;
            if (!(int)$t['receiver_received']) {
                $allReceived = false;
            }
        }
        foreach ($mtBoard['members'] as $m) {
            $mid = (int)$m['id'];
            $mtFeeByMember[$mid] = (float)($m['fee_amount'] ?? 0) + (float)($m['fee_receive'] ?? 0);
            $dSign = (int)($tracksByDeliverer[$mid]['deliverer_signed'] ?? 0) === 1;
            $rSign = (int)($tracksByReceiver[$mid]['receiver_signed'] ?? 0) === 1;
            $mtSignedByMember[$mid] = $dSign && $rSign;
        }
        $mtAllFeesPaid = true;
        $mtAllSigned   = true;
        foreach ($mtBoard['members'] as $m) {
            if (!(int)$m['fee_paid']) {
                $mtAllFeesPaid = false;
            }
            if (empty($mtSignedByMember[(int)$m['id']])) {
                $mtAllSigned = false;
            }
        }
        $mtDiffSettled = (int)($mtBoard['room']['diff_settled'] ?? 0) === 1;

        foreach ($mtMyTracks as $t) {
            if ((int)$t['deliverer_user_id'] === $uid) {
                $mtDeliverTrack = $t;
            }
            if ((int)$t['receiver_user_id'] === $uid) {
                $mtReceiveTrack = $t;
            }
        }
        if ($mtMyMember) {
            $mtMyFeeTotal  = (float)($mtMyMember['fee_amount'] ?? 0) + (float)($mtMyMember['fee_receive'] ?? 0);
            $mtMyFeePaid   = (int)($mtMyMember['fee_paid'] ?? 0) === 1;
            $mtMyDiff      = (float)($mtMyMember['diff_amount'] ?? 0);
            $mtMyDiffPaid  = (int)($mtMyMember['diff_paid'] ?? 0) === 1;
            $mtMySigned    = !empty($mtSignedByMember[(int)$mtMyMember['id']]);
        }
        if ($mtDeliverTrack) {
            $mtMyShippingSet = !empty($mtDeliverTrack['shipping_date']);
            $mtMyShipped     = (int)$mtDeliverTrack['deliverer_shipped'] === 1;
        }
        if ($mtReceiveTrack) {
            $mtMyReceived = (int)$mtReceiveTrack['receiver_received'] === 1;
        }
        $mtMyRated = true;
        foreach ($mtMyTracks as $t) {
            $rated = ((int)$t['deliverer_user_id'] === $uid) ? (int)$t['deliverer_rated'] : (int)$t['receiver_rated'];
            if (!$rated) {
                $mtMyRated = false;
            }
        }
        $mtAllReceived = $allReceived;
        $mtCompleted   = $mtStatus === 'completed';
    }

    $mtTakenTargets = [];
    foreach ($mtMembersAll as $m) {
        $t = (int)($m['target_member_id'] ?? 0);
        if ($t > 0 && (int)$m['id'] !== (int)($mtMyMember['id'] ?? 0)) {
            $mtTakenTargets[$t] = (int)$m['id'];
        }
    }
};

// اجرای اولیه محاسبات
$calculateMtState();


// عضویت کاربر در اتاق مبادله چندنفره (برای دسترسی اعضای جدید نفر ۳ تا ۵)
$mtViewMember   = $multiRoom ? multi_trade_member((int)$multiRoom['id'], $uid) : null;
$mtViewIsMember = (bool)($mtViewMember && in_array((string)$mtViewMember['status'], ['active', 'invited'], true));

$trade = fetch_trade_room($tradeId, $uid);
if (!$trade) {
    if ($mtViewIsMember) {
        // عضو اتاق که طرف معامله‌ی پایه نیست؛ امکان مشاهده‌ی اتاق امن را دارد.
        $trade = fetch_trade_room($tradeId, null);
    }
    if (!$trade) {
        // کاربر نه طرف معامله است و نه عضو اتاق؛ به فرم پیوستن هدایت می‌شود.
        if ($multiRoom && in_array((string)$multiRoom['status'], ['awaiting_seed', 'forming'], true)) {
            header('Location: ' . APP_URL . '/trades/group?id=' . (int)$multiRoom['id']);
            exit;
        }
        header('Location: ' . APP_URL . '/trades');
        exit;
    }
}
$isParty = in_array($uid, [(int)$trade['user_a_id'], (int)$trade['user_b_id']], true);

$isA       = (int)$trade['user_a_id'] === $uid;
$otherName = $isA ? $trade['user_b_name'] : $trade['user_a_name'];
$otherId   = $isA ? (int)$trade['user_b_id'] : (int)$trade['user_a_id'];

$creditDiff = (float)($trade['credit_diff'] ?? 0);
if ($creditDiff > 0) {
    $userToPayId = (int)$trade['user_b_id'];
    $amountToPay = $creditDiff;
} elseif ($creditDiff < 0) {
    $userToPayId = (int)$trade['user_a_id'];
    $amountToPay = abs($creditDiff);
} else {
    $userToPayId = 0;
    $amountToPay = 0;
}
$iOwe = $userToPayId === $uid;

$contract = get_trade_contract($tradeId);
if (!$contract) {
    create_trade_contract($tradeId);
    $contract = get_trade_contract($tradeId);
}

$success  = '';
$error    = '';
$myReview = DB::fetch(
    'SELECT id FROM reviews WHERE trade_id = ? AND from_user_id = ? LIMIT 1',
    [$tradeId, $uid]
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $action = clean($_POST['action'] ?? '');
    
    if ($action === 'select_payment_method') {
        $paymentMethod = clean($_POST['payment_method'] ?? '');
        if (in_array($paymentMethod, ['in_person', 'bnpl', 'cash'], true)) {
            if ($multiRoom && $mtMyMember) {
                DB::update('multi_trade_members', [
                    'payment_method' => $paymentMethod,
                ], 'id = ?', [(int)$mtMyMember['id']]);
            } else {
                DB::update('trades', [
                    'selected_payment_method' => $paymentMethod,
                ], 'id = ?', [$tradeId]);
            }
            $success = 'روش پرداخت با موفقیت انتخاب شد.';
            $trade = fetch_trade_room($tradeId, $uid) ?? $trade;
        }
    } elseif ($action === 'select_shipping_method') {
        $shippingMethod = clean($_POST['shipping_method'] ?? '');
        if (in_array($shippingMethod, ['courier', 'post', 'swapin_secure'], true)) {
            if ($multiRoom && $mtDeliverTrack) {
                $dbMethod = $shippingMethod === 'swapin_secure' ? 'in_person' : $shippingMethod;
                DB::update('multi_trade_tracks', [
                    'shipping_method' => $dbMethod,
                ], 'id = ?', [(int)$mtDeliverTrack['id']]);
            } else {
                DB::update('trades', [
                    'selected_shipping_method' => $shippingMethod,
                ], 'id = ?', [$tradeId]);
            }
            $success = 'روش ارسال با موفقیت انتخاب شد.';
            $trade = fetch_trade_room($tradeId, $uid) ?? $trade;
        }
    } elseif ($action === 'update_shipping_status') {
        $shippingStatus = clean($_POST['shipping_status'] ?? '');
        $validStatuses = ['preparing', 'shipped', 'dispute'];
        if (in_array($shippingStatus, $validStatuses, true)) {
            if ($isA) {
                DB::update('trades', ['user_a_shipping_status' => $shippingStatus], 'id = ?', [$tradeId]);
            } else {
                DB::update('trades', ['user_b_shipping_status' => $shippingStatus], 'id = ?', [$tradeId]);
            }
            $success = 'وضعیت ارسال با موفقیت به‌روزرسانی شد.';
            $trade = fetch_trade_room($tradeId, $uid) ?? $trade;
        }
    }

    if ($action === 'send_message') {
        $body = clean($_POST['body'] ?? '');
        if ($body !== '') {
            DB::insert('secure_room_messages', [
                'trade_id' => $tradeId,
                'user_id'  => $uid,
                'type'     => 'text',
                'body'     => $body,
            ]);
            $success = 'پیام ارسال شد.';
        }
    } elseif ($action === 'pay_fee' && !trade_user_fee_paid($trade, $isA)) {
        $result = pay_trade_user_fee($tradeId, $uid);
        if (isset($result['error'])) {
            if (isset($result['user_id']) && (int)$result['user_id'] === $uid) {
                $_SESSION['error'] = $result['error'];
                header('Location: ' . WALLET_TOPUP_URL . '?amount=' . ($result['required_amount'] ?? 0));
                exit;
            }
            $error = $result['error'];
        } elseif (!empty($result['already_paid'])) {
            $success = 'کارمزد شما قبلاً پرداخت شده است.';
        } else {
            $success = 'کارمزد شما با موفقیت پرداخت شد.';
        }
    } elseif ($action === 'pay_diff' && !(int)$trade['diff_paid']) {
        $selectedPaymentMethod = $trade['selected_payment_method'] ?? '';
        if (empty($selectedPaymentMethod)) {
            $error = 'لطفاً ابتدا روش پرداخت را انتخاب کنید.';
        } elseif ($selectedPaymentMethod === 'in_person') {
            // Payment in person, just mark it as paid without deducting from wallet
            DB::query('UPDATE trades SET diff_paid = 1, step = 4 WHERE id = ?', [$tradeId]);
            $success = 'روش پرداخت در محل انتخاب شد. اختلاف قیمت هنگام تحویل کالا پرداخت خواهد شد.';
        } else {
            // Cash or BNPL, deduct from wallet
            $myBalance = (float)($user['credit_balance'] ?? 0);
            if ($myBalance < $amountToPay) {
                $_SESSION['error'] = 'موجودی کیف پول شما کافی نیست. مبلغ موردنیاز: ' . fmt_credit($amountToPay - $myBalance);
            } else {
                escrow_hold($tradeId, $uid, $amountToPay, 'سپرده مابه‌التفاوت معامله #' . $tradeId);
                DB::query('UPDATE trades SET diff_paid = 1, step = 4 WHERE id = ?', [$tradeId]);
                $success = 'اختلاف قیمت با موفقیت پرداخت شد.';
            }
        }
    } elseif ($action === 'sign_contract') {
        if (!trade_fees_fully_paid($trade)) {
            $error = 'ابتدا هر دو طرف باید کارمزد خود را پرداخت کنند.';
        } elseif (sign_trade_contract($tradeId, $uid)) {
            $success = 'قرارداد با موفقیت امضا شد.';
            if (contract_fully_signed($tradeId)) {
                DB::query('UPDATE trades SET step = 5 WHERE id = ?', [$tradeId]);
            }
        } else { 
            $error = 'امضای قرارداد ممکن نشد.';
        }
    } elseif ($action === 'set_shipping') {
        $shippingDateInput = clean($_POST['shipping_date'] ?? '');
        $shippingTime      = clean($_POST['shipping_time'] ?? '');
        $shippingMethod    = $trade['selected_shipping_method'] ?? '';
        $shippingDate      = parse_shipping_date_input($shippingDateInput);

        if (!$shippingDate) {
            $error = 'تاریخ شمسی را به‌درستی وارد کنید (مثال: ۱۴۰۴/۰۴/۲۳).';
        } elseif (!$shippingTime) {
            $error = 'ساعت ارسال را وارد کنید.';
        } elseif (!in_array($shippingMethod, ['courier', 'post', 'swapin_secure'], true)) {
            $error = 'ابتدا روش ارسال را از پایین صفحه انتخاب کنید.';
        } else {
            if ($isA) {
                DB::update('trades', [
                    'user_a_shipping_date'   => $shippingDate,
                    'user_a_shipping_time'   => $shippingTime,
                    'user_a_shipping_method' => $shippingMethod,
                ], 'id = ?', [$tradeId]);
            } else {
                DB::update('trades', [
                    'user_b_shipping_date'   => $shippingDate,
                    'user_b_shipping_time'   => $shippingTime,
                    'user_b_shipping_method' => $shippingMethod,
                ], 'id = ?', [$tradeId]);
            }
            $success = 'زمان ارسال ثبت شد.';
            $trade = fetch_trade_room($tradeId, $uid) ?? $trade;
            $aReady = !empty($trade['user_a_shipping_date']) && !empty($trade['user_a_shipping_time']);
            $bReady = !empty($trade['user_b_shipping_date']) && !empty($trade['user_b_shipping_time']);
            $shippingSelected = !empty($trade['selected_shipping_method']);
            if ($aReady && $bReady && $shippingSelected) {
                DB::query('UPDATE trades SET step = 6 WHERE id = ?', [$tradeId]);
            }
        }
    } elseif ($action === 'mark_shipped') {
        if ($isA) {
            DB::update('trades', ['user_a_delivered' => 1], 'id = ?', [$tradeId]);
        } else {
            DB::update('trades', ['user_b_delivered' => 1], 'id = ?', [$tradeId]);
        }
        $success = 'وضعیت ارسال شما ثبت شد.';
        $trade = fetch_trade_room($tradeId, $uid) ?? $trade;
        if (!empty($trade['user_a_delivered']) && !empty($trade['user_b_delivered'])) {
            DB::query('UPDATE trades SET step = 7 WHERE id = ?', [$tradeId]);
        }
    } elseif ($action === 'save_tracking') {
        $code = clean($_POST['tracking_code'] ?? '');
        if ($code === '') {
            $error = 'کد رهگیری را وارد کنید.';
        } elseif ($isA) {
            DB::update('trades', ['tracking_code_a' => $code], 'id = ?', [$tradeId]);
            $success = 'کد رهگیری شما ثبت شد.';
        } else {
            DB::update('trades', ['tracking_code_b' => $code], 'id = ?', [$tradeId]);
            $success = 'کد رهگیری شما ثبت شد.';
        }
    } elseif ($action === 'confirm_received') {
        if ($isA) {
            DB::update('trades', ['user_a_received' => 1], 'id = ?', [$tradeId]);
        } else {
            DB::update('trades', ['user_b_received' => 1], 'id = ?', [$tradeId]);
        }
        $success = 'دریافت کالا تایید شد.';
        $trade = fetch_trade_room($tradeId, $uid) ?? $trade;
        if (!empty($trade['user_a_received']) && !empty($trade['user_b_received'])) {
            DB::query('UPDATE trades SET step = 9 WHERE id = ?', [$tradeId]);
        }
    } elseif ($action === 'confirm_trade') {
        if (!contract_fully_signed($tradeId)) {
            $error = 'ابتدا باید قرارداد توسط هر دو طرف امضا شود.';
        } else {
            if ($isA) {
                DB::query('UPDATE trades SET status = "user_a_confirmed" WHERE id = ? AND status = "in_progress"', [$tradeId]);
            } else {
                DB::query('UPDATE trades SET status = "user_b_confirmed" WHERE id = ? AND status = "user_a_confirmed"', [$tradeId]);
            }

            $trade = DB::fetch('SELECT * FROM trades WHERE id = ?', [$tradeId]) ?: $trade;
            if (($trade['status'] ?? '') === 'user_b_confirmed') {
                $result = complete_trade($tradeId);
                if (isset($result['error'])) {
                    $_SESSION['error'] = $result['error'];
                    if (isset($result['user_id']) && (int)$result['user_id'] === $uid) {
                        header('Location: ' . WALLET_TOPUP_URL . '?amount=' . ($result['required_amount'] ?? 0));
                        exit;
                    }
                    header('Location: ' . APP_URL . '/trades?trade=' . $tradeId);
                    exit;
                }
                DB::query('UPDATE trades SET step = 10 WHERE id = ?', [$tradeId]);
                $success = 'معامله نهایی شد و سپرده آزاد شد.';
            } else {
                $success = 'تایید شما ثبت شد. در انتظار تایید طرف مقابل.';
            }
        }
    } elseif ($action === 'request_multi_trade') {
        $res = multi_trade_create_from_trade($tradeId, $uid, false);
        if (isset($res['error'])) {
            $error = $res['error'];
        } else {
            $success = 'درخواست مبادله چندنفره ثبت شد. در انتظار تأیید طرف مقابل.';
            $multiRoom = multi_trade_room_for_seed_trade($tradeId);
        }
    } elseif ($action === 'accept_invite') {
        if ($multiRoom) {
            $res = multi_trade_accept_invite((int)$multiRoom['id'], $uid);
            if (isset($res['error'])) $error = $res['error'];
            else $success = 'دعوت با موفقیت پذیرفته شد. اکنون اعضای جدید می‌توانند درخواست دهند.';
        }
    } elseif ($action === 'decline_invite') {
        if ($multiRoom) {
            $res = multi_trade_decline_invite((int)$multiRoom['id'], $uid);
            if (isset($res['error'])) $error = $res['error'];
            else $success = 'دعوت رد شد و اتاق لغو گردید.';
        }
    } elseif ($action === 'vote_join') {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $approve   = ((int)($_POST['approve'] ?? 0)) === 1;
        $res = multi_trade_vote_join($requestId, $uid, $approve);
        if (isset($res['error'])) $error = $res['error'];
        else $success = 'نظر شما با موفقیت ثبت شد.';
    } elseif ($action === 'vote_close') {
        if ($multiRoom) {
            $res = multi_trade_vote_close((int)$multiRoom['id'], $uid);
            if (isset($res['error'])) $error = $res['error'];
            else $success = 'تأیید شما ثبت شد.';
        }
    } elseif ($action === 'select_target') {
        if ($multiRoom) {
            $res = multi_trade_select_target((int)$multiRoom['id'], $uid, (int)($_POST['target_member_id'] ?? 0));
            if (isset($res['error'])) {
                $error = $res['error'];
            } elseif (!empty($res['cycle_error'])) {
                $error = $res['cycle_error'];
            } else {
                $success = !empty($res['activated'])
                    ? 'همه انتخاب‌ها کامل شد؛ چرخه معامله فعال شد.'
                    : 'انتخاب شما ثبت شد.';
            }
        }
    } elseif ($action === 'leave_room') {
        if ($multiRoom) {
            $res = multi_trade_leave_room((int)$multiRoom['id'], $uid);
            if (isset($res['error'])) {
                $error = $res['error'];
            } else {
                header('Location: ' . APP_URL . '/trades');
                exit;
            }
        }
    } elseif ($action === 'mt_pay_fee') {
        if ($multiRoom) {
            $res = multi_trade_pay_fee((int)$multiRoom['id'], $uid);
            if (isset($res['error'])) $error = $res['error']; else $success = 'کارمزد شما پرداخت شد.';
        }
    } elseif ($action === 'mt_pay_diff') {
        if ($multiRoom) {
            $res = multi_trade_pay_diff((int)$multiRoom['id'], $uid);
            if (isset($res['error'])) $error = $res['error']; else $success = 'ماب‌تفاوت شما پرداخت شد.';
        }
    } elseif ($action === 'mt_sign_track') {
        $res = multi_trade_sign_track((int)($_POST['track_id'] ?? 0), $uid);
        if (isset($res['error'])) $error = $res['error']; else $success = 'قرارداد این مسیر امضا شد.';
    } elseif ($action === 'mt_sign_contract') {
        if ($multiRoom) {
            $res = multi_trade_sign_contract((int)$multiRoom['id'], $uid);
            if (isset($res['error'])) $error = $res['error']; else $success = 'قرارداد امضا شد و برای هر دو معامله تأیید گردید.';
        }
    } elseif ($action === 'mt_set_shipping') {
        $mtShipDate = parse_shipping_date_input(clean($_POST['shipping_date'] ?? ''));
        if (!$mtShipDate) {
            $error = 'تاریخ شمسی را به‌درستی وارد کنید (مثال: ۱۴۰۴/۰۴/۲۳).';
        } else {
            $res = multi_trade_set_track_shipping(
                (int)($_POST['track_id'] ?? 0), $uid,
                clean($_POST['method'] ?? ''), $mtShipDate, clean($_POST['shipping_time'] ?? '')
            );
            if (isset($res['error'])) $error = $res['error']; else $success = 'زمان و روش ارسال ثبت شد.';
        }
    } elseif ($action === 'mt_mark_shipped') {
        $res = multi_trade_mark_shipped((int)($_POST['track_id'] ?? 0), $uid, clean($_POST['tracking_code'] ?? ''));
        if (isset($res['error'])) $error = $res['error']; else $success = 'ارسال کالا ثبت شد.';
    } elseif ($action === 'mt_confirm_received') {
        $res = multi_trade_confirm_received((int)($_POST['track_id'] ?? 0), $uid);
        if (isset($res['error'])) $error = $res['error']; else $success = 'دریافت کالا تأیید شد.';
    } elseif ($action === 'mt_rate_track') {
        $res = multi_trade_rate_track((int)($_POST['track_id'] ?? 0), $uid, (int)($_POST['score'] ?? 0), clean($_POST['comment'] ?? ''));
        if (isset($res['error'])) $error = $res['error']; else $success = 'امتیاز شما ثبت شد.';
    } elseif ($action === 'mt_send_message') {
        if ($multiRoom) {
            $res = multi_trade_send_message((int)$multiRoom['id'], $uid, (string)($_POST['body'] ?? ''));
            if (isset($res['error'])) $error = $res['error'];
        }
    }

    $trade    = fetch_trade_room($tradeId, $uid) ?? $trade;
    $multiRoom = multi_trade_room_for_seed_trade($tradeId);

    $contract = get_trade_contract($tradeId) ?? $contract;
    $myReview = DB::fetch(
        'SELECT id FROM reviews WHERE trade_id = ? AND from_user_id = ? LIMIT 1',
        [$tradeId, $uid]
    );
}

if ($multiRoom) {
    $roomId        = (int)$multiRoom['id'];
    $mtStatus      = (string)$multiRoom['status'];
    $mtMyMember    = multi_trade_member($roomId, $uid);
    $mtIsInvited   = (bool)($mtMyMember && $mtMyMember['status'] === 'invited');
    $mtIAmActive   = (bool)($mtMyMember && $mtMyMember['status'] === 'active');
    $mtActiveCount = multi_trade_active_count($roomId);
    $mtMembersAll  = multi_trade_members($roomId, false);
    $mtLocked      = in_array($mtStatus, ['active', 'completed'], true);
    $mtRoomClosed  = in_array($mtStatus, ['locked', 'active', 'completed'], true);
    $mtMessages    = multi_trade_messages($roomId);
    if ($mtMyMember && $mtStatus === 'forming') {
        $mtPendingRequests = multi_trade_join_requests_pending($roomId);
    }
    if ($mtLocked) {
        $mtBoard    = multi_trade_board($roomId);
        $mtMyTracks = array_values(array_filter($mtBoard['tracks'], function ($t) use ($uid) {
            return (int)$t['deliverer_user_id'] === $uid || (int)$t['receiver_user_id'] === $uid;
        }));

        $tracksByDeliverer = [];
        $tracksByReceiver  = [];
        $allReceived = true;
        foreach ($mtBoard['tracks'] as $t) {
            $tracksByDeliverer[(int)$t['deliverer_member_id']] = $t;
            $tracksByReceiver[(int)$t['receiver_member_id']]   = $t;
            if (!(int)$t['receiver_received']) {
                $allReceived = false;
            }
        }
        foreach ($mtBoard['members'] as $m) {
            $mid = (int)$m['id'];
            $mtFeeByMember[$mid] = (float)($m['fee_amount'] ?? 0) + (float)($m['fee_receive'] ?? 0);
            $dSign = (int)($tracksByDeliverer[$mid]['deliverer_signed'] ?? 0) === 1;
            $rSign = (int)($tracksByReceiver[$mid]['receiver_signed'] ?? 0) === 1;
            $mtSignedByMember[$mid] = $dSign && $rSign;
        }
        $mtAllFeesPaid = true;
        $mtAllSigned   = true;
        foreach ($mtBoard['members'] as $m) {
            if (!(int)$m['fee_paid']) {
                $mtAllFeesPaid = false;
            }
            if (empty($mtSignedByMember[(int)$m['id']])) {
                $mtAllSigned = false;
            }
        }
        $mtDiffSettled = (int)($mtBoard['room']['diff_settled'] ?? 0) === 1;

        foreach ($mtMyTracks as $t) {
            if ((int)$t['deliverer_user_id'] === $uid) {
                $mtDeliverTrack = $t;
            }
            if ((int)$t['receiver_user_id'] === $uid) {
                $mtReceiveTrack = $t;
            }
        }
        if ($mtMyMember) {
            $mtMyFeeTotal  = (float)($mtMyMember['fee_amount'] ?? 0) + (float)($mtMyMember['fee_receive'] ?? 0);
            $mtMyFeePaid   = (int)($mtMyMember['fee_paid'] ?? 0) === 1;
            $mtMyDiff      = (float)($mtMyMember['diff_amount'] ?? 0);
            $mtMyDiffPaid  = (int)($mtMyMember['diff_paid'] ?? 0) === 1;
            $mtMySigned    = !empty($mtSignedByMember[(int)$mtMyMember['id']]);
        }
        if ($mtDeliverTrack) {
            $mtMyShippingSet = !empty($mtDeliverTrack['shipping_date']);
            $mtMyShipped     = (int)$mtDeliverTrack['deliverer_shipped'] === 1;
        }
        if ($mtReceiveTrack) {
            $mtMyReceived = (int)$mtReceiveTrack['receiver_received'] === 1;
        }
        $mtMyRated = true;
        foreach ($mtMyTracks as $t) {
            $rated = ((int)$t['deliverer_user_id'] === $uid) ? (int)$t['deliverer_rated'] : (int)$t['receiver_rated'];
            if (!$rated) {
                $mtMyRated = false;
            }
        }
        $mtAllReceived = $allReceived;
        $mtCompleted   = $mtStatus === 'completed';
    }
    // هدف‌های انتخاب‌شده توسط سایر اعضا (برای قفل‌کردن انتخاب‌های تکراری)
    foreach ($mtMembersAll as $m) {
        $t = (int)($m['target_member_id'] ?? 0);
        if ($t > 0 && (int)$m['id'] !== (int)($mtMyMember['id'] ?? 0)) {
            $mtTakenTargets[$t] = (int)$m['id'];
        }
    }
}

$messages = DB::fetchAll(
    'SELECT m.*, u.name AS user_name
     FROM secure_room_messages m
     JOIN users u ON u.id = m.user_id
     WHERE m.trade_id = ?
     ORDER BY m.created_at ASC',
    [$tradeId]
);

DB::query(
    'UPDATE secure_room_messages SET is_read = 1 WHERE trade_id = ? AND user_id != ?',
    [$tradeId, $uid]
);

function get_step_status(int $stepId, array $trade, array $contract, bool $hasReview): string
{
    $feePaid        = trade_fees_fully_paid($trade);
    $diffPaid       = !empty($trade['diff_paid']);
    $contractSigned = !empty($contract['user_a_signed']) && !empty($contract['user_b_signed']);
    $shippingReady  = trade_shipping_fully_scheduled($trade);
    $shipped        = !empty($trade['user_a_delivered']) && !empty($trade['user_b_delivered']);
    $trackingReady  = !empty($trade['tracking_code_a']) && !empty($trade['tracking_code_b']);
    $received       = !empty($trade['user_a_received']) && !empty($trade['user_b_received']);
    $completed      = ($trade['status'] ?? '') === 'completed';

    if ($stepId === 1 || $stepId === 2) return 'done';
    if ($stepId === 3) return $feePaid ? 'done' : 'current';
    if ($stepId === 4) return $contractSigned ? 'done' : ($feePaid ? 'current' : 'locked');
    if ($stepId === 5) {
        global $amountToPay;
        if ($amountToPay <= 0) return 'done';
        return $diffPaid ? 'done' : ($contractSigned ? 'current' : 'locked');
    }
    if ($stepId === 6) return $shippingReady ? 'done' : ($contractSigned ? 'current' : 'locked');
    if ($stepId === 7) return $received ? 'done' : ($shippingReady ? 'current' : 'locked');
    if ($stepId === 8) return ($completed && $hasReview) ? 'done' : ($received ? 'current' : 'locked');
    return 'locked';
}

function get_trade_flow_tab(array $trade, array $contract, bool $hasReview): string
{
    // تعیین تب پیشنهادی برای معامله دو‌نفره
    if (!trade_fees_fully_paid($trade)) return 'fee';
    if (empty($contract['user_a_signed']) || empty($contract['user_b_signed'])) return 'contract';
    global $amountToPay;
    if ($amountToPay > 0 && empty($trade['diff_paid'])) return 'diff';
    if (!trade_shipping_fully_scheduled($trade)) return 'shipping';
    if (empty($trade['user_a_received']) || empty($trade['user_b_received'])) return 'details';
    if (($trade['status'] ?? '') !== 'completed' || !$hasReview) return 'final';
    return 'chat';
}

/**
 * وضعیت گام‌های «وضعیت معامله» برای مبادله چندنفره (مثل معامله معمولی: سبز/نارنجی/قفل).
 */
function get_mt_step_status(int $stepId, array $mtBoard): string
{
    $room    = $mtBoard['room'] ?? [];
    $members = $mtBoard['members'] ?? [];
    $tracks  = $mtBoard['tracks'] ?? [];

    $allFeePaid = true;
    $allSigned  = true;
    foreach ($members as $m) {
        if (!(int)($m['fee_paid'] ?? 0)) {
            $allFeePaid = false;
        }
    }
    $tracksByDeliverer = [];
    $tracksByReceiver  = [];
    foreach ($tracks as $t) {
        $tracksByDeliverer[(int)$t['deliverer_member_id']] = $t;
        $tracksByReceiver[(int)$t['receiver_member_id']]   = $t;
    }
    foreach ($members as $m) {
        $mid = (int)$m['id'];
        $dSign = (int)($tracksByDeliverer[$mid]['deliverer_signed'] ?? 0) === 1;
        $rSign = (int)($tracksByReceiver[$mid]['receiver_signed'] ?? 0) === 1;
        if (!($dSign && $rSign)) {
            $allSigned = false;
        }
    }

    $diffSettled = (int)($room['diff_settled'] ?? 0) === 1;

    $allShipping = true;
    $allReceived = true;
    $allRated    = true;
    foreach ($tracks as $t) {
        if (empty($t['shipping_date'])) $allShipping = false;
        if (!(int)$t['receiver_received']) $allReceived = false;
        if (!(int)$t['deliverer_rated'] || !(int)$t['receiver_rated']) $allRated = false;
    }
    $completed = ($room['status'] ?? '') === 'completed' || ($allReceived && $allRated);

    if ($stepId === 1 || $stepId === 2) return 'done';
    if ($stepId === 3) return $allFeePaid ? 'done' : 'current';
    if ($stepId === 4) return $allSigned ? 'done' : ($allFeePaid ? 'current' : 'locked');
    if ($stepId === 5) return $diffSettled ? 'done' : ($allSigned ? 'current' : 'locked');
    if ($stepId === 6) return $allShipping ? 'done' : ($diffSettled ? 'current' : 'locked');
    if ($stepId === 7) return $allReceived ? 'done' : ($allShipping ? 'current' : 'locked');
    if ($stepId === 8) return $completed ? 'done' : ($allReceived ? 'current' : 'locked');
    return 'locked';
}

/**
 * تب پیشنهادی برای مبادله چندنفره بر اساس اولین مرحله‌ی فعال.
 */
function get_mt_flow_tab(array $mtBoard): string
{
    $map = [3 => 'fee', 4 => 'contract', 5 => 'diff', 6 => 'shipping', 7 => 'details', 8 => 'final'];
    foreach ($map as $sid => $tabKey) {
        if (get_mt_step_status($sid, $mtBoard) === 'current') {
            return $tabKey;
        }
    }
    return 'chat';
}


function get_step_datetime(int $stepId, array $trade, array $contract, bool $hasReview): ?string
{
    $createdAt = $trade['created_at'] ?? null;

    if ($stepId === 1 || $stepId === 2) {
        return $createdAt;
    }

    if ($stepId === 3 && trade_fees_fully_paid($trade)) {
        return $trade['updated_at'] ?? $createdAt;
    }

    if ($stepId === 4 && !empty($trade['contract_signed_at'])) {
        return $trade['contract_signed_at'];
    }

    if ($stepId === 5) {
        global $amountToPay;
        if ($amountToPay <= 0) {
            return null;
        }
        if (!empty($trade['diff_paid'])) {
            return $trade['updated_at'] ?? $createdAt;
        }
    }

    if ($stepId === 6) {
        $shippingDate = $trade['user_a_shipping_date'] ?? $trade['user_b_shipping_date'] ?? null;
        $shippingTime = $trade['user_a_shipping_time'] ?? $trade['user_b_shipping_time'] ?? null;
        if ($shippingDate) {
            return trim($shippingDate . ' ' . ($shippingTime ?: '00:00:00'));
        }
    }

    if ($stepId === 7 && !empty($trade['user_a_received']) && !empty($trade['user_b_received'])) {
        return $trade['updated_at'] ?? $createdAt;
    }

    if ($stepId === 8 && ($trade['status'] ?? '') === 'completed') {
        if ($hasReview) {
            return $trade['completed_at'] ?? $trade['updated_at'] ?? $createdAt;
        }
    }

    return null;
}

$hasReview       = (bool)$myReview;
$contractSigned  = !empty($contract['user_a_signed']) && !empty($contract['user_b_signed']);
$shippingReady   = trade_shipping_fully_scheduled($trade);
$myFeePaid       = trade_user_fee_paid($trade, $isA);
$theirFeePaid    = trade_user_fee_paid($trade, !$isA);
$feesFullyPaid   = trade_fees_fully_paid($trade);
$deliveredByMe   = $isA ? !empty($trade['user_a_delivered']) : !empty($trade['user_b_delivered']);
$deliveredByThem = $isA ? !empty($trade['user_b_delivered']) : !empty($trade['user_a_delivered']);
$trackingMine    = $isA ? (string)($trade['tracking_code_a'] ?? '') : (string)($trade['tracking_code_b'] ?? '');
$trackingTheirs  = $isA ? (string)($trade['tracking_code_b'] ?? '') : (string)($trade['tracking_code_a'] ?? '');
$receivedMine    = $isA ? !empty($trade['user_a_received']) : !empty($trade['user_b_received']);
$receivedTheirs  = $isA ? !empty($trade['user_b_received']) : !empty($trade['user_a_received']);
$receivedAll     = !empty($trade['user_a_received']) && !empty($trade['user_b_received']);
$completedTrade  = ($trade['status'] ?? '') === 'completed';

$feeA = trade_user_fee_amount($trade, true);
$feeB = trade_user_fee_amount($trade, false);
$myFee = $isA ? $feeA : $feeB;

$allowedTabs = ['chat', 'fee', 'contract', 'diff', 'shipping', 'details', 'final'];
$recommendedTab = ($multiRoom && $mtBoard)
    ? get_mt_flow_tab($mtBoard)
    : get_trade_flow_tab($trade, $contract, $hasReview);
if (isset($_GET['accepted'])) {
    $recommendedTab = trade_fees_fully_paid($trade) ? $recommendedTab : 'fee';
}
$requestedTab = clean($_GET['tab'] ?? $recommendedTab);
$tab = in_array($requestedTab, $allowedTabs, true) ? $requestedTab : $recommendedTab;

$myProduct = $isA ? [
    'title' => $trade['listing_a_title'],
    'img'   => !empty($trade['img_a']) ? UPLOAD_URL . $trade['img_a'] : '',
    'val'   => $trade['listing_a_val'] ?? 0,
] : [
    'title' => $trade['listing_b_title'] ?: 'در حال تسویه با اعتبار',
    'img'   => !empty($trade['img_b']) ? UPLOAD_URL . $trade['img_b'] : '',
    'val'   => $trade['listing_b_val'] ?? 0,
];

$otherProduct = $isA ? [
    'title' => $trade['listing_b_title'] ?: 'تسویه اعتباری',
    'img'   => !empty($trade['img_b']) ? UPLOAD_URL . $trade['img_b'] : '',
    'val'   => $trade['listing_b_val'] ?? 0,
] : [
    'title' => $trade['listing_a_title'],
    'img'   => !empty($trade['img_a']) ? UPLOAD_URL . $trade['img_a'] : '',
    'val'   => $trade['listing_a_val'] ?? 0,
];

$summaryShippingMethod = $shippingReady
    ? shipping_label((string)($isA ? ($trade['user_a_shipping_method'] ?? '') : ($trade['user_b_shipping_method'] ?? '')))
        . ' / ' . shipping_label((string)($isA ? ($trade['user_b_shipping_method'] ?? '') : ($trade['user_a_shipping_method'] ?? '')))
    : 'در انتظار انتخاب زمان و روش ارسال';
$summaryPaymentMethod = $amountToPay > 0
    ? 'کیف پول امن سواَپین / نگهداری امانی'
    : 'بدون اختلاف قیمت';

$tabLabels = [
    'chat'     => 'چت',
    'fee'      => 'پرداخت کارمزد',
    'contract' => 'تایید قرارداد',
    'diff'     => 'پرداخت اختلاف قیمت',
    'shipping' => 'ارسال',
    'details'  => 'دریافت',
    'final'    => 'تایید نهایی و ثبت نظر',
];

// Timeline items mapped to tabs
$timelineItems = [
    ['id' => 1, 'title' => 'ثبت پیشنهاد', 'tab' => 'chat', 'date' => null],
    ['id' => 2, 'title' => 'پذیرش پیشنهاد', 'tab' => 'chat', 'date' => null],
    ['id' => 3, 'title' => 'پرداخت کارمزد', 'tab' => 'fee', 'date' => null],
    ['id' => 4, 'title' => 'تایید قرارداد', 'tab' => 'contract', 'date' => null],
    ['id' => 5, 'title' => 'پرداخت اختلاف قیمت', 'tab' => 'diff', 'date' => null],
    ['id' => 6, 'title' => 'ارسال', 'tab' => 'shipping', 'date' => null],
    ['id' => 7, 'title' => 'دریافت', 'tab' => 'details', 'date' => null],
    ['id' => 8, 'title' => 'تایید نهایی و ثبت نظر', 'tab' => 'final', 'date' => null],
];

// Fetch all user's trades for dropdown
$allTrades = DB::fetchAll(
    'SELECT t.id, t.status,
        ua.name as user_a_name, ub.name as user_b_name,
        la.title as listing_a_title, lb.title as listing_b_title
     FROM trades t
     JOIN users ua ON ua.id = t.user_a_id
     JOIN users ub ON ub.id = t.user_b_id
     JOIN listings la ON la.id = t.listing_a_id
     LEFT JOIN listings lb ON lb.id = t.listing_b_id
     WHERE t.user_a_id = ? OR t.user_b_id = ?
     ORDER BY t.created_at DESC',
    [$uid, $uid]
);

// Fetch received offers for modal
$receivedOffers = DB::fetchAll(
    'SELECT o.*, l.title AS listing_title, u.name AS from_name, u.avatar AS from_avatar,
        ol.title AS offer_listing_title,
        (SELECT filename FROM listing_images WHERE listing_id=ol.id AND is_primary=1 LIMIT 1) AS offer_listing_thumb
     FROM trade_offers o
     JOIN listings l ON l.id = o.listing_id
     JOIN users u ON u.id = o.from_user_id
     LEFT JOIN listings ol ON ol.id = o.offer_listing_id
     WHERE l.user_id = ? AND o.status = "pending"
     ORDER BY o.created_at DESC',
    [$uid]
);

render_head('اتاق امن معامله #' . $tradeId, 'اتاق امن معامله و مدیریت مرحله‌به‌مرحله تبادل', [
    'robots' => 'noindex, nofollow',
]);
render_panel_styles();
echo '<link rel="stylesheet" href="' . APP_URL . '/src/css/secure-trade.css">' . "\n";
?>
<style>
.mt-pill { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:999px; font-size:11px; font-weight:700; }
.mt-pill--forming { background: rgba(0,102,255,.10); color:#0066ff; }
.mt-pill--locked  { background: rgba(255,201,40,.18); color:#8a6d00; }
.mt-pill--active  { background: rgba(7,26,51,.08); color:#071A33; }
.mt-pill--completed { background: rgba(20,160,90,.12); color:#128a4d; }
.mt-notify { display:flex; align-items:center; gap:10px; background: linear-gradient(135deg, #FFF6DC 0%, #FFEDB8 100%); border:1px solid #FFC928; color:#7a6100; border-radius:14px; padding:12px 14px; font-size:13px; font-weight:700; margin-bottom:14px; }
.mt-notify i { font-size:18px; }
.mt-member-row { display:flex; align-items:center; gap:10px; padding:10px; background:#f8fafd; border-radius:12px; margin-bottom:8px; }
.mt-member-av { width:34px; height:34px; border-radius:10px; background:#071A33; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:13px; flex:0 0 auto; }
.mt-member-name { font-weight:700; font-size:13px; color:#071A33; }
.mt-member-meta { font-size:11px; color:#6c7a92; }
.mt-blur-wrap { position:relative; border-radius:14px; overflow:hidden; }
.mt-blur-body { filter: blur(4px); opacity:.55; pointer-events:none; user-select:none; }
.mt-blur-note { position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:6px; text-align:center; font-size:12px; font-weight:700; color:#7a6100; background:rgba(255,255,255,.55); padding:10px; }
.mt-target-option { display:flex; align-items:center; gap:8px; padding:9px 11px; border:1px solid #e6ebf3; border-radius:12px; margin-bottom:8px; font-size:13px; }
.mt-target-option.is-taken { opacity:.5; background:#f4f6fa; }
.mt-cycle { display:flex; flex-wrap:wrap; gap:8px; align-items:center; font-size:12px; }
.mt-cycle .mt-node { background:#f1f4f9; border-radius:10px; padding:5px 9px; font-weight:700; color:#071A33; }
.mt-track-mini { display:flex; justify-content:space-between; align-items:center; gap:8px; border:1px solid #e6ebf3; border-radius:12px; padding:9px 11px; margin-bottom:8px; font-size:12px; background:#fbfcfe; }
.mt-track-mini__flow { font-weight:700; color:#071A33; }
.mt-badge { font-size:11px; padding:2px 8px; border-radius:999px; font-weight:700; white-space:nowrap; }
.mt-badge--ok { background:rgba(20,160,90,.12); color:#128a4d; }
.mt-badge--wait { background:rgba(255,102,0,.12); color:#c25200; }
.mt-tabs { display:flex; gap:8px; margin-bottom:14px; border-bottom:1px solid #e6ebf3; }
.mt-tab { border:none; background:transparent; cursor:pointer; padding:10px 14px; font-size:14px; font-weight:700; color:#6c7a92; border-bottom:3px solid transparent; font-family:inherit; }
.mt-tab.is-active { color:#071A33; border-bottom-color:#FFC928; }
.mt-track { border:1px solid #e6ebf3; border-radius:14px; padding:14px; margin-bottom:12px; background:#fff; }
.mt-track--mine { border-color:#FFC928; box-shadow:0 4px 16px rgba(255,201,40,.18); }
.mt-track__head { display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; }
.mt-track__flow { display:flex; align-items:center; gap:8px; font-weight:700; color:#071A33; font-size:13px; }
.mt-track__step { font-size:12px; color:#6c7a92; margin-top:6px; }
.mt-actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }
.mt-input, .mt-select { border:1px solid #dbe3ee; border-radius:10px; padding:8px 10px; font-size:13px; font-family:inherit; }
.mt-btn { display:inline-flex; align-items:center; gap:6px; border:none; cursor:pointer; border-radius:12px; padding:9px 15px; font-weight:700; font-size:13px; font-family:inherit; }
.mt-btn--primary { background:#071A33; color:#fff; }
.mt-btn--gold { background:#FFC928; color:#071A33; }
.mt-btn--danger { background:#ffe9e6; color:#c0392b; }
.mt-parties { display:flex; flex-wrap:wrap; justify-content:center; align-items:center; gap:14px 18px; width:100%; padding:12px 6px; }
.mt-party { display:flex; flex-direction:row; align-items:center; gap:12px; text-align:right; background:#f7f9fc; border:1px solid #e6ebf3; border-radius:16px; padding:10px 16px; min-width:160px; box-shadow:0 2px 8px rgba(7,26,51,.04); }
.mt-party__av { width:46px; height:46px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:18px; flex:0 0 auto; }
.mt-party__name { font-size:14px; font-weight:800; color:#071A33; white-space:nowrap; }
.mt-party__meta { font-size:11px; color:#6c7a92; margin-top:2px; }
.mt-party__arrow { display:flex; flex-direction:column; align-items:center; gap:2px; color:#94a3b8; flex:0 0 auto; }
.mt-party__arrow i { font-size:18px; }
.mt-party__arrow span { font-size:9px; color:#8a6d00; white-space:nowrap; max-width:90px; overflow:hidden; text-overflow:ellipsis; }
@media (max-width:1023px) {
  .mt-parties { gap:10px 12px; }
  .mt-party { min-width:0; padding:8px 12px; }
  .mt-party__av { width:38px; height:38px; font-size:15px; }
}
</style>
<?php
render_navbar($user);
render_user_panel_open($user, 'trades');
?>

<div class="trade-room">
  <!-- New Header -->
  <header class="trade-room__hero">
    <div class="trade-room__hero-left">
      <a href="<?= APP_URL ?>/trades" class="trade-room__back">
        <i class="bi bi-arrow-right"></i>
        بازگشت
      </a>
    </div>
    <div class="trade-room__hero-center">
      <h1>اتاق امن معامله #<?= $tradeId ?></h1>
      <p>لطفاً مراحل معامله را فقط از طریق این اتاق دنبال کنید.</p>
    </div>
    <div class="trade-room__hero-right">
      <!-- Trade Selector Dropdown -->
      <div class="trade-room__dropdown">
        <select class="trade-room__select" onchange="window.location.href='?id='+this.value">
          <?php foreach ($allTrades as $t):
            $otherName = ((int)$t['user_a_id'] === $uid) ? $t['user_b_name'] : $t['user_a_name'];
            $listingTitle = ((int)$t['user_a_id'] === $uid) ? $t['listing_a_title'] : $t['listing_b_title'];
            $listingTitle = $listingTitle ?? 'تسویه اعتباری';
          ?>
            <option value="<?= $t['id'] ?>" <?= $t['id'] == $tradeId ? 'selected' : '' ?>>
              معامله با <?= h($otherName) ?> - <?= h(mb_substr($listingTitle, 0, 30)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Offers Button -->
      <button type="button" class="trade-room__hero-btn" id="openOffersModal">
        <i class="bi bi-inbox"></i>
        پیشنهادها
        <?php
          $pendingCount = 0;
          foreach ($receivedOffers as $o) {
            if ($o['status'] === 'pending') $pendingCount++;
          }
          if ($pendingCount > 0):
        ?>
          <span class="trade-room__badge"><?= $pendingCount ?></span>
        <?php endif; ?>
      </button>

      <?php if (!in_array((string)$trade['status'], ['completed', 'cancelled'], true) && !$multiRoom): ?>
      <form method="post" style="display:inline;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="request_multi_trade">
        <button type="submit" class="trade-room__hero-btn" onclick="return confirm('درخواست تبدیل این معامله به «مبادله چندنفره» (۳ تا ۵ نفر) ثبت شود؟');">
          <i class="bi bi-people-fill"></i>
          مبادله چندنفره
        </button>
      </form>
      <?php endif; ?>

      <a href="<?= APP_URL ?>/fraud-prevention" class="trade-room__hero-btn">
        <i class="bi bi-question-circle"></i>
        راهنمای معامله
      </a>
    </div>
  </header>

  <?php if ($multiRoom && !$mtLocked && $mtStatus !== 'cancelled'): ?>
    <section class="trade-room__card" style="margin-bottom: var(--sp-4); border: 2px solid #FFC928;">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:10px;">
        <h2 style="font-size:18px; color:#071A33; margin:0;">
          <i class="bi bi-people-fill" style="color:#FFC928;"></i>
          وضعیت مبادله چندنفره
        </h2>
        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
          <span class="mt-pill mt-pill--<?= h($mtStatus === 'awaiting_seed' ? 'forming' : $mtStatus) ?>">
            <?= h(multi_trade_room_status_label($mtStatus)) ?>
          </span>
          <?php if ($mtIAmActive && in_array($mtStatus, ['awaiting_seed', 'forming'], true)): ?>
            <?php if ($mtActiveCount <= 2): ?>
            <form method="post" onsubmit="return confirm('با این کار معامله به‌طور کامل بسته می‌شود و آگهی‌ها به حالت فعال برمی‌گردند. مطمئن هستید؟');">
              <?= csrf_field() ?><input type="hidden" name="action" value="leave_room">
              <button type="submit" class="trade-room__hero-btn" style="background:#ffe9e6; color:#c0392b; border:none;"><i class="bi bi-x-octagon"></i> ترک و بستن معامله</button>
            </form>
            <?php else: ?>
            <form method="post" onsubmit="return confirm('آیا از ترک این معامله مطمئن هستید؟ فقط شما از گروه خارج می‌شوید.');">
              <?= csrf_field() ?><input type="hidden" name="action" value="leave_room">
              <button type="submit" class="trade-room__hero-btn" style="background:#ffe9e6; color:#c0392b; border:none;"><i class="bi bi-box-arrow-right"></i> ترک معامله</button>
            </form>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- درخواست‌های پیوستن — بلافاصله زیر عنوان «وضعیت مبادله چندنفره» -->
      <?php if ($mtPendingRequests): ?>
        <div class="mt-notify" style="margin-bottom:12px;">
          <i class="bi bi-bell-fill"></i>
          <span><b><?= count($mtPendingRequests) ?></b> درخواست پیوستن جدید — تأیید <b>همه‌ی</b> اعضای اتاق لازم است.</span>
        </div>
        <?php foreach ($mtPendingRequests as $r): ?>
          <?php
            $reqVotes = DB::fetchAll("SELECT member_id, decision FROM multi_trade_join_votes WHERE request_id = ?", [(int)$r['id']]);
            $voteBy   = [];
            foreach ($reqVotes as $rv) { $voteBy[(int)$rv['member_id']] = (string)$rv['decision']; }
            $myVote   = $voteBy[(int)($mtMyMember['id'] ?? 0)] ?? null;
          ?>
          <div style="background:#fff; border:1px solid #e6ebf3; border-radius:12px; padding:12px; margin-bottom:10px;">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
              <div style="font-size:13px;">
                <b><?= h($r['user_name']) ?></b> قصد پیوستن با آگهی «<?= h($r['listing_title']) ?>» را دارد.
                <span style="color:#6c7a92; font-size:11px;">(تأییدها: <?= (int)$r['approve_count'] ?>/<?= $mtActiveCount ?>)</span>
              </div>
              <div style="display:flex; gap:5px;">
                <?php if ($myVote): ?>
                  <span style="font-size:11px; padding:2px 8px; border-radius:999px; background:<?= $myVote === 'approve' ? '#e8f5e9;color:#2e7d32' : '#ffebee;color:#c62828' ?>;">
                    نظر شما: <?= $myVote === 'approve' ? 'موافق' : 'مخالف' ?>
                  </span>
                <?php else: ?>
                  <form method="post"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="vote_join"><input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="approve" value="1">
                    <button class="trade-room__hero-btn" style="padding:4px 10px; font-size:11px; background:#e8f5e9; color:#2e7d32; border:none;" type="submit"><i class="bi bi-check2"></i> موافقم</button>
                  </form>
                  <form method="post"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="vote_join"><input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="approve" value="0">
                    <button class="trade-room__hero-btn" style="padding:4px 10px; font-size:11px; background:#ffebee; color:#c62828; border:none;" type="submit"><i class="bi bi-x"></i> مخالفم</button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
            <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:10px; border-top:1px dashed #e6ebf3; padding-top:10px;">
              <?php foreach ($mtMembersAll as $m): if ($m['status'] !== 'active') continue; ?>
                <?php $dec = $voteBy[(int)$m['id']] ?? null; ?>
                <span style="font-size:11px; padding:3px 9px; border-radius:999px; background:<?= $dec === 'approve' ? '#e8f5e9;color:#2e7d32' : ($dec === 'reject' ? '#ffebee;color:#c62828' : '#f1f4f9;color:#6c7a92') ?>;">
                  <?= h($m['user_name']) ?>: <?= $dec === 'approve' ? 'موافق' : ($dec === 'reject' ? 'مخالف' : 'در انتظار') ?>
                </span>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php if ($mtIsInvited): ?>
        <div style="background:#f8fafd; border-radius:12px; padding:15px; border:1px dashed #dbe3ee;">
          <p style="font-size:14px; margin-bottom:12px;">طرف مقابل درخواست داده این معامله به‌صورت <b>«مبادله چندنفره» (۳ تا ۵ نفر)</b> انجام شود. با تأیید شما، این اتاق برای پذیرش اعضای جدید باز می‌شود.</p>
          <div style="display:flex; gap:10px;">
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="accept_invite">
              <button class="trade-room__hero-btn" style="background:#FFC928; color:#071A33; border:none;" type="submit"><i class="bi bi-check2-circle"></i> تأیید و فعال‌سازی</button>
            </form>
            <form method="post" onsubmit="return confirm('با رد دعوت، اتاق لغو می‌شود. مطمئن هستید؟');"><?= csrf_field() ?><input type="hidden" name="action" value="decline_invite">
              <button class="trade-room__hero-btn" style="background:#ffe9e6; color:#c0392b; border:none;" type="submit"><i class="bi bi-x-circle"></i> رد درخواست</button>
            </form>
          </div>
        </div>
      <?php elseif ($mtStatus === 'awaiting_seed'): ?>
        <div class="trade-room__alert trade-room__alert--info">
          در انتظار تأیید طرف مقابل برای تبدیل به مبادله چندنفره...
        </div>
      <?php else: ?>

        <!-- لیست اعضا -->
        <h3 style="font-size:15px; margin:0 0 10px;"><i class="bi bi-people"></i> اعضای اتاق (<?= $mtActiveCount ?> از <?= (int)$multiRoom['members_max'] ?>)</h3>
        <?php foreach ($mtMembersAll as $m): ?>
          <div class="mt-member-row">
            <div class="mt-member-av"><?= h(mb_substr((string)$m['user_name'], 0, 1)) ?></div>
            <div style="flex:1;">
              <div class="mt-member-name"><?= h($m['user_name']) ?> <?= (int)$m['user_id'] === $uid ? '(شما)' : '' ?></div>
              <div class="mt-member-meta">
                <?= h($m['listing_title'] ?? 'بدون آگهی') ?>
                —
                <?= $m['status'] === 'active' ? 'عضو فعال' : ($m['status'] === 'invited' ? 'در انتظار تأیید' : 'خارج‌شده') ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>

        <!-- بستن اتاق (۳ تا ۵ عضو) -->
        <?php if ($mtStatus === 'forming' && $mtActiveCount >= 3 && $mtIAmActive): ?>
          <div style="margin-top:14px; border-top:1px solid #e6ebf3; padding-top:14px;">
            <p style="font-size:13px; color:#42536e; margin-bottom:10px;">با تأیید <b>همه‌ی</b> اعضا، اتاق بسته می‌شود. تا آن زمان تأیید درخواست‌های پیوستن همچنان ممکن است.</p>
            <?php if ((int)$mtMyMember['close_vote']): ?>
              <button class="trade-room__hero-btn" disabled><i class="bi bi-hourglass-split"></i> منتظر تأیید بقیه اعضا</button>
            <?php else: ?>
              <form method="post" onsubmit="return confirm('با تأیید شما، اتاق تنها در صورت موافقت همه‌ی اعضا بسته می‌شود. مطمئن هستید؟');"><?= csrf_field() ?>
                <input type="hidden" name="action" value="vote_close">
                <button class="trade-room__hero-btn" style="background:#FFC928; color:#071A33; border:none;" type="submit"><i class="bi bi-lock-fill"></i> بستن اتاق</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <!-- انتخاب طرف معامله (پس از بستن اتاق) -->
        <?php if ($mtStatus === 'locked' && $mtIAmActive): ?>
          <div style="margin-top:14px; border-top:1px solid #e6ebf3; padding-top:14px;">
            <h3 style="font-size:15px; margin-bottom:8px;"><i class="bi bi-arrow-left-right"></i> انتخاب طرف معامله</h3>
            <p style="font-size:13px; color:#42536e; margin-bottom:12px;">دقیقاً یک نفر را انتخاب کنید؛ هر عضو فقط یک‌بار می‌تواند انتخاب شود.</p>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="select_target">
              <?php foreach ($mtMembersAll as $m): ?>
                <?php
                  if ($m['status'] !== 'active' || (int)$m['id'] === (int)$mtMyMember['id']) continue;
                  $taken = isset($mtTakenTargets[(int)$m['id']]);
                  $isMine = (int)($mtMyMember['target_member_id'] ?? 0) === (int)$m['id'];
                ?>
                <label class="mt-target-option <?= $taken ? 'is-taken' : '' ?>">
                  <input type="radio" name="target_member_id" value="<?= (int)$m['id'] ?>" <?= $isMine ? 'checked' : '' ?> <?= $taken ? 'disabled' : '' ?> required>
                  <span><b><?= h($m['user_name']) ?></b> — <?= h($m['listing_title'] ?? 'بدون آگهی') ?></span>
                  <?php if ($taken): ?><span style="margin-inline-start:auto; font-size:11px; color:#6c7a92;">قبلاً انتخاب شده</span><?php endif; ?>
                </label>
              <?php endforeach; ?>
              <button type="submit" class="trade-room__hero-btn" style="background:#FFC928; color:#071A33; border:none; margin-top:6px;"><i class="bi bi-check2-circle"></i> ثبت انتخاب</button>
            </form>
          </div>
        <?php endif; ?>

      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php if ($isParty): ?>
  <!-- Parties Header moved up -->
  <section class="trade-room__card" style="margin-bottom: var(--sp-4);display: flex;justify-content: center;">
    <?php if ($multiRoom): ?>
      <?php
        $mtHeaderMembers = array_values(array_filter($mtMembersAll, function ($mm) { return $mm['status'] === 'active'; }));
        $mtGiveTo = [];
        if ($mtBoard) {
            foreach ($mtBoard['tracks'] as $t) {
                $mtGiveTo[(int)$t['deliverer_member_id']] = $t['receiver_name'];
            }
            // ترتیب اعضا بر اساس چرخه‌ی معامله (کی به کی می‌دهد)
            if (!empty($mtBoard['cycle'])) {
                $byId = [];
                foreach ($mtHeaderMembers as $mm) { $byId[(int)$mm['id']] = $mm; }
                $ordered = [];
                foreach ($mtBoard['cycle'] as $cid) {
                    if (isset($byId[(int)$cid])) { $ordered[] = $byId[(int)$cid]; unset($byId[(int)$cid]); }
                }
                foreach ($mtHeaderMembers as $mm) { if (isset($byId[(int)$mm['id']])) { $ordered[] = $mm; unset($byId[(int)$mm['id']]); } }
                $mtHeaderMembers = $ordered;
            }
        }
      ?>
      <div class="mt-parties">
        <?php foreach ($mtHeaderMembers as $idx => $m): ?>
          <?php $isMe = (int)$m['user_id'] === $uid; ?>
          <div class="mt-party">
            <div class="mt-party__av" style="background: <?= $isMe ? '#081B45' : '#eef2f8' ?>; color: <?= $isMe ? '#fff' : '#42536e' ?>;">
              <?= h(mb_substr($m['user_name'], 0, 1)) ?>
            </div>
            <div>
              <div class="mt-party__name"><?= $isMe ? 'شما' : h($m['user_name']) ?></div>
              <div class="mt-party__meta"><?= h($m['user_city'] ?? 'شهر نامشخص') ?></div>
            </div>
          </div>
          <?php if ($idx < count($mtHeaderMembers) - 1): ?>
            <?php $giveTo = $mtGiveTo[(int)$m['id']] ?? null; ?>
            <div class="mt-party__arrow" title="<?= h($m['user_name'] . ($giveTo ? ' → ' . $giveTo : '')) ?>">
              <i class="bi bi-arrow-left"></i>
              <?php if ($giveTo): ?><span><?= h($giveTo) ?></span><?php endif; ?>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="trade-room__parties" style="display: grid; grid-template-columns: 37% 15% 30%; gap: 20px;align-items: center;width: 100%;">
        <div class="trade-room__party" style="display: flex; align-items: center; justify-content: center; gap: 12px;">
          <div class="trade-room__avatar" style="margin: 0;"><?= h(mb_substr($user['name'], 0, 1)) ?></div>
          <div style="text-align: right;">
            <div class="trade-room__party-name">شما</div>
            <div class="trade-room__party-meta"><?= h($user['city'] ?? 'شهر نامشخص') ?></div>
          </div>
        </div>

        <section class="trade-room__status-banner">
          <i class="bi bi-arrow-left-right" style="font-size: 37px; color: #081B45;"></i>
          <span>معامله در حال انجام</span>
        </section>

        <div class="trade-room__party" style="display: flex; align-items: center; justify-content: center; gap: 12px;">
          <div class="trade-room__avatar" style="margin: 0;"><?= h(mb_substr($otherName, 0, 1)) ?></div>
          <div style="text-align: left;">
            <div class="trade-room__party-name"><?= h($otherName) ?></div>
            <div class="trade-room__party-meta">طرف مقابل</div>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <!-- Mobile Steps Button -->
  <div class="trade-room__mobile-steps-btn-container">
    <button type="button" class="trade-room__mobile-steps-btn" id="openMobileStepsModal">
      <i class="bi bi-list-check"></i>
      مراحل معامله
    </button>
  </div>

  <!-- Alerts -->
  <?php if (isset($_GET['accepted'])): ?>
  <div class="alert alert-success">
    <i class="bi bi-check-circle"></i>
    پیشنهاد پذیرفته شد! لطفاً مراحل معامله را از همین اتاق امن دنبال کنید.
  </div>
  <?php endif; ?>

  <?php if (isset($_SESSION['error'])): ?>
  <div class="alert alert-danger">
    <i class="bi bi-exclamation-circle"></i>
    <?= h($_SESSION['error']) ?>
  </div>
  <?php unset($_SESSION['error']); ?>
  <?php endif; ?>

  <?php if ($success): ?>
  <div class="alert alert-success">
    <i class="bi bi-check-circle"></i>
    <?= h($success) ?>
  </div>
  <?php endif; ?>

  <?php if ($error): ?>
  <div class="alert alert-danger">
    <i class="bi bi-exclamation-circle"></i>
    <?= h($error) ?>
  </div>
  <?php endif; ?>

  <div class="trade-room__layout">
    <?php if ($multiRoom && (string)$multiRoom['status'] === 'cancelled'): ?>
      <div style="grid-column: 1 / -1; background: #fff; border-radius: 16px; padding: 40px; text-align: center; border: 1px solid #eee; box-shadow: var(--st-card-shadow);">
        <div style="width: 80px; height: 80px; background: #fff5f5; color: #e53e3e; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 40px;">
          <i class="bi bi-x-circle"></i>
        </div>
        <h2 style="color: #071A33; margin-bottom: 10px;">این مبادله منحل شده است</h2>
        <p style="color: #666; margin-bottom: 30px;">اتاق مبادله چندنفره توسط یکی از اعضا بسته شد و آگهی‌ها به حالت فعال بازگشتند.</p>
        <a href="<?= APP_URL ?>/trades" class="btn btn-primary" style="padding: 10px 30px;">بازگشت به معاملات من</a>
      </div>
    <?php elseif ($multiRoom && !$mtLocked && $mtStatus !== 'cancelled'): ?>
      <!-- نمای مبادله چندنفره: اتاق هنوز بسته نشده؛ «وضعیت معامله» و «جزئیات/مراحل» blur است -->
      <aside class="trade-room__timeline-column">
        <section class="trade-room__timeline-card">
          <h3 class="trade-room__card-title">وضعیت معامله</h3>
          <div class="mt-blur-wrap">
            <div class="mt-blur-body">
              <div class="trade-room__timeline">
                <?php foreach ($timelineItems as $item): ?>
                  <div class="trade-room__timeline-item trade-room__timeline-item--readonly">
                    <div class="trade-room__timeline-dot"></div>
                    <div class="trade-room__timeline-body">
                      <div class="trade-room__timeline-title"><?= h($item['title']) ?></div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="mt-blur-note">
              <i class="bi bi-lock-fill" style="font-size:18px;"></i>
              پس از بسته‌شدن اتاق و انتخاب طرف معامله فعال می‌شود.
            </div>
          </div>
        </section>

        <section class="trade-room__timeline-card trade-room__support">
          <h3 class="trade-room__card-title"><i class="bi bi-headset"></i> پشتیبانی</h3>
          <p class="trade-room__muted">این معامله به‌صورت گروهی در حال انجام است.</p>
          <a href="<?= APP_URL ?>/support" class="btn btn-outline-secondary w-100 btn-sm">تماس با ما</a>
        </section>
      </aside>

      <div class="trade-room__content">
        <!-- تب‌ها (چت + تحویل/دریافت من) — تا فعال‌شدن چرخه، کل تب‌ها blur است -->
        <div class="mt-blur-wrap">
          <div class="mt-blur-body">
            <nav class="trade-room__tabs" aria-label="مراحل معامله">
              <span class="trade-room__tab trade-room__tab--active">
                <i class="bi bi-chat-dots trade-room__tab-icon"></i><span>گفتگوی گروهی</span>
              </span>
              <span class="trade-room__tab">
                <i class="bi bi-box-seam trade-room__tab-icon"></i><span>تحویل من</span>
              </span>
              <span class="trade-room__tab">
                <i class="bi bi-inbox trade-room__tab-icon"></i><span>دریافت من</span>
              </span>
            </nav>
            <section class="trade-room__panel" style="min-height: 240px;"></section>
          </div>
          <div class="mt-blur-note">
            <i class="bi bi-lock-fill" style="font-size:18px;"></i>
            گفتگو و مراحل معامله پس از بسته‌شدن اتاق و انتخاب طرف معامله فعال می‌شود.
          </div>
        </div>
      </div>

      <aside class="trade-room__details-column">
        <section class="trade-room__card">
          <h3 class="trade-room__card-title">جزئیات معامله</h3>
          <div class="mt-blur-wrap">
            <div class="mt-blur-body">
              <?php foreach ($mtMembersAll as $m): ?>
                <div class="mt-member-row">
                  <div class="mt-member-av"><?= h(mb_substr((string)$m['user_name'], 0, 1)) ?></div>
                  <div style="flex:1;">
                    <div class="mt-member-name"><?= h($m['user_name']) ?></div>
                    <div class="mt-member-meta"><?= h($m['listing_title'] ?? 'بدون آگهی') ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="mt-blur-note">
              <i class="bi bi-lock-fill" style="font-size:18px;"></i>
              پس از فعال‌شدن چرخه معامله نمایش داده می‌شود.
            </div>
          </div>
        </section>
      </aside>

    <?php elseif ($multiRoom): ?>
      <!-- مبادله چندنفره فعال: دقیقاً هم‌شکل «اتاق امن» معامله معمولی (سبز/نارنجی) -->
      <aside class="trade-room__timeline-column">
        <section class="trade-room__timeline-card">
        <h3 class="trade-room__card-title">وضعیت معامله</h3>
        <div class="trade-room__timeline">
          <?php foreach ($timelineItems as $item): ?>
            <?php $mtStepStatus = get_mt_step_status($item['id'], $mtBoard); ?>
            <?php if ($mtStepStatus === 'current'): ?>
            <a href="?id=<?= $tradeId ?>&tab=<?= h($item['tab']) ?>" class="trade-room__timeline-item trade-room__timeline-item--<?= $mtStepStatus ?>" style="text-decoration: none; display: block;">
            <?php else: ?>
            <div class="trade-room__timeline-item trade-room__timeline-item--<?= $mtStepStatus ?> trade-room__timeline-item--readonly">
            <?php endif; ?>
              <div class="trade-room__timeline-dot"></div>
              <div class="trade-room__timeline-body">
                <div class="trade-room__timeline-title"><?= h($item['title']) ?></div>
              </div>
            <?php if ($mtStepStatus === 'current'): ?>
            </a>
            <?php else: ?>
            </div>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="trade-room__timeline-card trade-room__support">
        <h3 class="trade-room__card-title">
          <i class="bi bi-headset"></i>
          نیاز به کمک دارید؟
        </h3>
        <p class="trade-room__muted">این معامله به‌صورت گروهی در حال انجام است.</p>
        <div class="trade-room__cta-row">
          <a href="<?= APP_URL ?>/support" class="btn btn-primary w-100">تماس با پشتیبانی</a>
        </div>
      </section>
    </aside>

      <div class="trade-room__content">
        <?php $mtTabIcons = [
          'chat'     => 'bi-chat-dots',
          'fee'      => 'bi-credit-card',
          'contract' => 'bi-file-earmark-text',
          'diff'     => 'bi-cash',
          'shipping' => 'bi-truck',
          'details'  => 'bi-box-seam',
          'final'    => 'bi-check-circle',
        ]; ?>
        <nav class="trade-room__tabs" aria-label="مراحل معامله">
          <?php foreach ($tabLabels as $tabKey => $label): ?>
            <a href="?id=<?= $tradeId ?>&tab=<?= h($tabKey) ?>" class="trade-room__tab <?= $tab === $tabKey ? 'trade-room__tab--active' : '' ?>">
              <i class="bi <?= $mtTabIcons[$tabKey] ?? 'bi-circle' ?> trade-room__tab-icon"></i>
              <span><?= h($label) ?></span>
            </a>
          <?php endforeach; ?>
        </nav>

        <section class="trade-room__panel">
          <?php if ($tab === 'chat'): ?>
            <h3 class="trade-room__card-title trade-room__card-title--center">
              <i class="bi bi-people"></i>
              گفتگوی گروهی
            </h3>
            <p class="trade-room__muted trade-room__muted--center">پیام‌ها بین همه‌ی اعضای اتاق به‌اشتراک گذاشته می‌شود.</p>
            <div class="trade-room__chat-list">
              <?php if (empty($mtMessages)): ?>
                <div class="trade-room__chat-empty">هنوز پیامی ثبت نشده است.</div>
              <?php else: ?>
                <?php foreach ($mtMessages as $msg): ?>
                  <?php $isMine = (int)($msg['user_id'] ?? 0) === $uid; ?>
                  <?php if ($msg['type'] === 'system'): ?>
                    <div class="trade-room__chat-item" style="justify-content:center;">
                      <div class="trade-room__chat-bubble" style="background:#f1f4f9; color:#6c7a92; font-size:11px;">
                        <div><?= h($msg['body']) ?></div>
                      </div>
                    </div>
                  <?php else: ?>
                    <div class="trade-room__chat-item <?= $isMine ? 'trade-room__chat-item--mine' : 'trade-room__chat-item--other' ?>">
                      <?php if (!$isMine): ?>
                        <div class="trade-room__chat-avatar"><?= h(mb_substr((string)($msg['user_name'] ?? '?'), 0, 1)) ?></div>
                      <?php endif; ?>
                      <div class="trade-room__chat-bubble">
                        <div><?= h($msg['body']) ?></div>
                        <div class="trade-room__chat-meta"><?= h((string)($msg['user_name'] ?? '')) ?> · <?= persian_datetime($msg['created_at']) ?></div>
                      </div>
                      <?php if ($isMine): ?>
                        <div class="trade-room__chat-avatar"><?= h(mb_substr((string)$user['name'], 0, 1)) ?></div>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>

            <div class="trade-room__composer">
              <form method="POST" class="trade-room__composer-input">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="mt_send_message">
                <input type="text" name="body" placeholder="پیام خود را بنویسید..." autocomplete="off" required>
              </form>
              <button type="button" class="trade-room__send" onclick="this.parentNode.querySelector('form').submit()">
                <i class="bi bi-send"></i>
              </button>
            </div>

          <?php elseif ($tab === 'fee'): ?>
            <h3 class="trade-room__card-title"><i class="bi bi-credit-card"></i> پرداخت کارمزد</h3>
            <div class="trade-room__action">
              <h4>وضعیت کارمزد پلتفرم</h4>
              <p>هر عضو باید کارمزد هر دو معامله‌ی خود (کالایی که می‌گیرد و کالایی که پس می‌دهد) را پرداخت کند.</p>
              <div class="trade-room__meta-box" style="margin: var(--sp-4) 0;">
                <?php foreach ($mtBoard['members'] as $m): ?>
                  <div class="trade-room__meta-line">
                    <span><?= h($m['user_name']) ?><?= (int)$m['user_id'] === $uid ? ' (شما)' : '' ?> — کارمزد: <?= fmt_credit((float)($mtFeeByMember[(int)$m['id']] ?? 0)) ?></span>
                    <span class="trade-room__pill <?= (int)($m['fee_paid'] ?? 0) ? 'trade-room__pill--success' : 'trade-room__pill--warning' ?>">
                      <?= (int)($m['fee_paid'] ?? 0) ? 'پرداخت شده' : 'در انتظار' ?>
                    </span>
                  </div>
                <?php endforeach; ?>
              </div>
              <?php if ($mtAllFeesPaid): ?>
                <span class="trade-room__pill trade-room__pill--success">کارمزد همه‌ی اعضا پرداخت شد</span>
              <?php elseif (!$mtMyFeePaid): ?>
                <div class="trade-room__notice">کارمزد شما (مجموع دو معامله): <strong><?= fmt_credit($mtMyFeeTotal) ?></strong></div>
                <form method="POST" class="trade-room__cta-row">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="mt_pay_fee">
                  <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check-circle"></i> پرداخت کارمزد</button>
                </form>
              <?php else: ?>
                <span class="trade-room__pill trade-room__pill--success">کارمزد شما پرداخت شده است</span>
                <div class="trade-room__notice">در انتظار پرداخت کارمزد سایر اعضا هستیم.</div>
              <?php endif; ?>
            </div>

          <?php elseif ($tab === 'contract'): ?>
            <h3 class="trade-room__card-title"><i class="bi bi-file-earmark-text"></i> تایید قرارداد</h3>
            <?php if (!$mtAllFeesPaid): ?>
              <div class="trade-room__notice">ابتدا از تب «پرداخت کارمزد» همه‌ی اعضا باید کارمزد خود را پرداخت کنند.</div>
            <?php else: ?>
              <div class="trade-room__action">
                <h4>امضای قرارداد</h4>
                <p>با امضای شما، قرارداد هر دو معامله‌ی شما (کالایی که می‌گیرید و کالایی که پس می‌دهید) یک‌جا تأیید می‌شود.</p>
                <div class="trade-room__meta-box">
                  <?php foreach ($mtBoard['members'] as $m): ?>
                    <div class="trade-room__meta-line">
                      <span><?= h($m['user_name']) ?><?= (int)$m['user_id'] === $uid ? ' (شما)' : '' ?></span>
                      <span class="trade-room__pill <?= !empty($mtSignedByMember[(int)$m['id']]) ? 'trade-room__pill--success' : 'trade-room__pill--warning' ?>">
                        <?= !empty($mtSignedByMember[(int)$m['id']]) ? 'امضا شده' : 'در انتظار امضا' ?>
                      </span>
                    </div>
                  <?php endforeach; ?>
                </div>
                <?php if ($mtAllSigned): ?>
                  <span class="trade-room__pill trade-room__pill--success">قرارداد همه‌ی اعضا امضا شد</span>
                <?php elseif (!$mtMySigned): ?>
                  <div class="trade-room__cta-row">
                    <form method="POST" class="w-100">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="mt_sign_contract">
                      <button type="submit" class="btn btn-primary w-100">امضای قرارداد</button>
                    </form>
                  </div>
                <?php else: ?>
                  <span class="trade-room__pill trade-room__pill--success">شما قرارداد را امضا کردید</span>
                  <div class="trade-room__notice">در انتظار امضای سایر اعضا هستیم.</div>
                <?php endif; ?>
              </div>
            <?php endif; ?>

          <?php elseif ($tab === 'diff'): ?>
            <h3 class="trade-room__card-title"><i class="bi bi-cash"></i> پرداخت اختلاف قیمت</h3>
            <div class="trade-room__action">
              <h4>اختلاف قیمت</h4>
              <div class="trade-room__meta-box" style="margin-bottom: var(--sp-4);">
                <?php foreach ($mtBoard['members'] as $m): ?>
                  <?php $mDiff = (float)($m['diff_amount'] ?? 0); ?>
                  <div class="trade-room__meta-line">
                    <span><?= h($m['user_name']) ?><?= (int)$m['user_id'] === $uid ? ' (شما)' : '' ?></span>
                    <span class="trade-room__pill <?= ($mDiff <= 0 || (int)($m['diff_paid'] ?? 0)) ? 'trade-room__pill--success' : 'trade-room__pill--warning' ?>">
                      <?= $mDiff > 0 ? ((int)($m['diff_paid'] ?? 0) ? 'پرداخت شده' : 'در انتظار پرداخت') : 'بدون بدهی' ?>
                    </span>
                  </div>
                <?php endforeach; ?>
              </div>
              <?php if ($mtMyDiff <= 0): ?>
                <p>شما بدهی اختلاف قیمتی ندارید<?= $mtMyDiff < 0 ? ' و طلبکار ' . fmt_credit(abs($mtMyDiff)) . ' هستید' : '' ?>.</p>
                <span class="trade-room__pill trade-room__pill--success">نیازی نیست</span>
              <?php elseif ($mtMyDiffPaid): ?>
                <p>اختلاف قیمت شما با موفقیت در امانت سواَپین ثبت شده است.</p>
                <span class="trade-room__pill trade-room__pill--success">پرداخت شده</span>
              <?php else: ?>
                <p>شما باید مبلغ <strong><?= fmt_credit($mtMyDiff) ?></strong> را برای ادامه معامله پرداخت کنید.</p>
                <form method="POST">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="mt_pay_diff">
                  <button type="submit" class="btn btn-accent w-100">پرداخت اختلاف قیمت</button>
                </form>
              <?php endif; ?>
            </div>

          <?php elseif ($tab === 'shipping'): ?>
            <h3 class="trade-room__card-title"><i class="bi bi-truck"></i> ارسال</h3>
            <?php if (!$mtAllSigned): ?>
              <div class="trade-room__notice">ابتدا از تب «تایید قرارداد» امضای همه‌ی اعضا را تکمیل کنید.</div>
            <?php elseif (!$mtDeliverTrack): ?>
              <div class="trade-room__notice">در این چرخه کالایی برای ارسال توسط شما ثبت نشده است.</div>
            <?php else: ?>
              <div class="trade-room__stack">
                <div class="trade-room__action">
                  <h4>زمان و روش ارسال کالای شما</h4>
                  <p>کالای «<?= h($mtDeliverTrack['deliverer_listing_title'] ?? '—') ?>» برای <b><?= h($mtDeliverTrack['receiver_name']) ?></b></p>
                  <?php if ($mtMyShippingSet): ?>
                    <span class="trade-room__pill trade-room__pill--success"><?= persian_date((string)$mtDeliverTrack['shipping_date']) ?> · <?= h(substr((string)($mtDeliverTrack['shipping_time'] ?? '00:00:00'), 0, 5)) ?></span>
                    <div class="fs-sm mt-2" style="color:var(--text-muted)">روش: <?= h(shipping_label((string)($mtDeliverTrack['shipping_method'] ?? ''))) ?></div>
                  <?php else: ?>
                    <form method="POST" class="trade-room__stack">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="mt_set_shipping">
                      <input type="hidden" name="track_id" value="<?= (int)$mtDeliverTrack['id'] ?>">
                      <div class="form-group">
                        <label class="form-label">تاریخ ارسال (شمسی)</label>
                        <input type="text" name="shipping_date" class="form-control jalali-date-input" data-jdp data-jdp-only-date placeholder="۱۴۰۴/۰۴/۲۳" autocomplete="off" required>
                      </div>
                      <div class="form-group">
                        <label class="form-label">ساعت ارسال</label>
                        <input type="time" name="shipping_time" class="form-control" required>
                      </div>
                      <div class="form-group">
                        <label class="form-label">روش ارسال</label>
                        <select name="method" class="form-control" required>
                          <option value="in_person">حضوری</option>
                          <option value="post">پست</option>
                          <option value="tipax">تیپاکس</option>
                          <option value="courier">پیک</option>
                        </select>
                      </div>
                      <button type="submit" class="btn btn-primary">ثبت زمان ارسال</button>
                    </form>
                  <?php endif; ?>
                </div>
                <?php if ($mtMyShippingSet && !$mtMyShipped): ?>
                <div class="trade-room__action">
                  <h4>ثبت ارسال کالا</h4>
                  <form method="POST" class="trade-room__stack">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="mt_mark_shipped">
                    <input type="hidden" name="track_id" value="<?= (int)$mtDeliverTrack['id'] ?>">
                    <input type="text" name="tracking_code" class="form-control" placeholder="کد رهگیری (اختیاری)">
                    <button type="submit" class="btn btn-primary">ثبت ارسال</button>
                  </form>
                </div>
                <?php elseif ($mtMyShipped): ?>
                  <span class="trade-room__pill trade-room__pill--success">ارسال کالای شما ثبت شد</span>
                <?php endif; ?>
              </div>
            <?php endif; ?>

          <?php elseif ($tab === 'details'): ?>
            <h3 class="trade-room__card-title"><i class="bi bi-box-seam"></i> دریافت</h3>
            <?php if (!$mtDiffSettled): ?>
              <div class="trade-room__notice">ابتدا باید تسویه اختلاف قیمت و مراحل قبلی تکمیل شود.</div>
            <?php elseif (!$mtReceiveTrack): ?>
              <div class="trade-room__notice">در این چرخه کالایی برای دریافت توسط شما ثبت نشده است.</div>
            <?php else: ?>
              <div class="trade-room__action">
                <h4>تایید دریافت کالا</h4>
                <p>کالای «<?= h($mtReceiveTrack['deliverer_listing_title'] ?? '—') ?>» از <b><?= h($mtReceiveTrack['deliverer_name']) ?></b></p>
                <?php if ($mtMyReceived): ?>
                  <span class="trade-room__pill trade-room__pill--success">دریافت شما تایید شده است</span>
                <?php elseif (!(int)$mtReceiveTrack['deliverer_shipped']): ?>
                  <div class="trade-room__notice">هنوز کالا توسط فرستنده ارسال نشده است.</div>
                <?php else: ?>
                  <p>اگر کالا را بدون مشکل تحویل گرفته‌اید، این مرحله را تایید کنید.</p>
                  <form method="POST" onsubmit="return confirm('دریافت کالا را تأیید می‌کنید؟');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="mt_confirm_received">
                    <input type="hidden" name="track_id" value="<?= (int)$mtReceiveTrack['id'] ?>">
                    <button type="submit" class="btn btn-primary">کالا را دریافت کردم</button>
                  </form>
                <?php endif; ?>
              </div>
            <?php endif; ?>

          <?php elseif ($tab === 'final'): ?>
            <h3 class="trade-room__card-title"><i class="bi bi-check-circle"></i> تایید نهایی و ثبت نظر</h3>
            <?php if (!$mtAllReceived): ?>
              <div class="trade-room__notice">ابتدا از تب «دریافت» تایید دریافت توسط همه‌ی اعضا را تکمیل کنید.</div>
            <?php elseif ($mtCompleted): ?>
              <div class="trade-room__notice" style="color:#128a4d; font-weight:700;">مبادله چندنفره با موفقیت تکمیل شد.</div>
            <?php else: ?>
              <p class="trade-room__muted">برای تکمیل نهایی، هر عضو باید به معامله‌های خود امتیاز دهد.</p>
              <div class="trade-room__stack">
                <?php foreach ($mtMyTracks as $t): ?>
                  <?php
                    $iAmDeliverer = (int)$t['deliverer_user_id'] === $uid;
                    $myRated      = $iAmDeliverer ? (int)$t['deliverer_rated'] : (int)$t['receiver_rated'];
                    $peerName     = $iAmDeliverer ? $t['receiver_name'] : $t['deliverer_name'];
                    $peerListing  = $iAmDeliverer ? ($t['receiver_listing_title'] ?? '—') : ($t['deliverer_listing_title'] ?? '—');
                  ?>
                  <div class="trade-room__action">
                    <h4><?= $iAmDeliverer ? 'امتیاز به گیرنده' : 'امتیاز به فرستنده' ?>: <?= h($peerName) ?></h4>
                    <p class="trade-room__muted">کالا: «<?= h($peerListing) ?>»</p>
                    <?php if ($myRated): ?>
                      <span class="trade-room__pill trade-room__pill--success">امتیاز شما ثبت شد</span>
                    <?php else: ?>
                      <form method="POST" class="trade-room__stack">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="mt_rate_track">
                        <input type="hidden" name="track_id" value="<?= (int)$t['id'] ?>">
                        <select name="score" class="form-control" required>
                          <option value="5">⭐⭐⭐⭐⭐ 5</option>
                          <option value="4">⭐⭐⭐⭐ 4</option>
                          <option value="3">⭐⭐⭐ 3</option>
                          <option value="2">⭐⭐ 2</option>
                          <option value="1">⭐ 1</option>
                        </select>
                        <textarea name="comment" rows="3" class="form-control" placeholder="نظر شما (اختیاری)"></textarea>
                        <button type="submit" class="btn btn-primary">ثبت امتیاز</button>
                      </form>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </section>
      </div>

      <aside class="trade-room__details-column">
        <section class="trade-room__card trade-room__card--compact">
          <h3 class="trade-room__card-title">جزئیات معامله</h3>
          <?php
            $mtDeliverByMember = [];
            $mtReceiveByMember = [];
            $mtThumbByMember   = [];
            foreach ($mtBoard['members'] as $mm) {
                $mtThumbByMember[(int)$mm['id']] = (string)($mm['listing_thumb'] ?? '');
            }
            foreach ($mtBoard['tracks'] as $t) {
                $mtDeliverByMember[(int)$t['deliverer_member_id']] = $t;
                $mtReceiveByMember[(int)$t['receiver_member_id']] = $t;
            }
          ?>
          <?php foreach ($mtBoard['members'] as $m): ?>
            <?php
              $mid       = (int)$m['id'];
              $giveTrack = $mtDeliverByMember[$mid] ?? null;
              $getTrack  = $mtReceiveByMember[$mid] ?? null;
              $isMe      = (int)$m['user_id'] === $uid;
              $giveTitle = $giveTrack['deliverer_listing_title'] ?? ($m['listing_title'] ?? 'بدون آگهی');
              $giveVal   = (float)($m['listing_value'] ?? 0);
              $giveImg   = (string)($m['listing_thumb'] ?? '');
              $getTitle  = $getTrack['deliverer_listing_title'] ?? '';
              $getImg    = $getTrack ? ($mtThumbByMember[(int)$getTrack['deliverer_member_id']] ?? '') : '';
              $getVal    = 0.0;
              if ($getTrack) {
                  $gm = $mtBoard['memberById'][(int)$getTrack['deliverer_member_id']] ?? null;
                  $getVal = $gm ? (float)($gm['listing_value'] ?? 0) : 0.0;
              }
            ?>
            <div style="border:1px solid #ECEFF5; border-radius:14px; padding:12px; margin-bottom:12px; background:#fff;">
              <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                <div class="mt-member-av"><?= h(mb_substr((string)$m['user_name'], 0, 1)) ?></div>
                <div style="flex:1;">
                  <div class="mt-member-name"><?= h($m['user_name']) ?><?= $isMe ? ' (شما)' : '' ?></div>
                  <div class="mt-member-meta"><?= h($m['user_city'] ?? 'شهر نامشخص') ?></div>
                </div>
              </div>

              <div class="trade-room__product-card">
                <?php if ($giveImg): ?>
                  <img src="<?= h(UPLOAD_URL . $giveImg) ?>" alt="<?= h($giveTitle) ?>" class="trade-room__product-thumb">
                <?php else: ?>
                  <div class="trade-room__product-thumb"><i class="bi bi-image"></i></div>
                <?php endif; ?>
                <div>
                  <div class="trade-room__product-title"><?= h($giveTitle) ?></div>
                  <div class="trade-room__product-meta">پس می‌دهد<?= $giveTrack ? ' · به ' . h($giveTrack['receiver_name']) : '' ?> · <?= $giveVal > 0 ? fmt_credit($giveVal) : 'بدون ارزش‌گذاری' ?></div>
                </div>
              </div>

              <div class="trade-room__swap-divider"><span class="trade-room__swap-divider-icon"><i class="bi bi-arrow-left-right"></i></span></div>

              <div class="trade-room__product-card">
                <?php if ($getImg): ?>
                  <img src="<?= h(UPLOAD_URL . $getImg) ?>" alt="<?= h($getTitle) ?>" class="trade-room__product-thumb">
                <?php else: ?>
                  <div class="trade-room__product-thumb"><i class="bi bi-image"></i></div>
                <?php endif; ?>
                <div>
                  <div class="trade-room__product-title"><?= h($getTitle ?: '—') ?></div>
                  <div class="trade-room__product-meta">می‌گیرد<?= $getTrack ? ' · از ' . h($getTrack['deliverer_name']) : '' ?> · <?= $getVal > 0 ? fmt_credit($getVal) : 'بدون ارزش‌گذاری' ?></div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </section>

        <section class="trade-room__summary-card trade-room__summary-card--wide">
          <h4 class="trade-room__summary-head">اختلاف قیمت</h4>
          <?php foreach ($mtBoard['members'] as $m): $d = (float)($m['diff_amount'] ?? 0); ?>
            <div class="trade-room__meta-line trade-room__meta-line--compact" style="direction: ltr;">
              <span><?= h($m['user_name']) ?></span>
              <span class="trade-room__summary-value <?= $d > 0 ? 'trade-room__summary-value--warning' : 'trade-room__summary-value--success' ?>">
                <?= $d == 0.0 ? 'بدون اختلاف' : ($d > 0 ? 'بدهکار ' . fmt_credit($d) : 'طلبکار ' . fmt_credit(abs($d))) ?>
              </span>
            </div>
          <?php endforeach; ?>
        </section>

        <section class="trade-room__summary-card trade-room__summary-card--wide">
          <h4 class="trade-room__summary-head">کارمزد سواَپین (هر عضو)</h4>
          <?php foreach ($mtBoard['members'] as $m): ?>
            <div class="trade-room__meta-line trade-room__meta-line--compact" style="direction: ltr;">
              <span><?= h($m['user_name']) ?></span>
              <span class="trade-room__summary-value"><?= fmt_credit((float)($mtFeeByMember[(int)$m['id']] ?? 0)) ?></span>
            </div>
          <?php endforeach; ?>
        </section>

        <section class="trade-room__summary-card trade-room__summary-card--wide">
          <h4 class="trade-room__summary-head">وضعیت پرداخت کارمزد</h4>
          <?php foreach ($mtBoard['members'] as $m): ?>
            <div class="trade-room__meta-line trade-room__meta-line--compact" style="direction: ltr;">
              <span><?= h($m['user_name']) ?></span>
              <span class="trade-room__pill <?= (int)($m['fee_paid'] ?? 0) ? 'trade-room__pill--success' : 'trade-room__pill--warning' ?>">
                <?= (int)($m['fee_paid'] ?? 0) ? 'پرداخت شده' : 'در انتظار' ?>
              </span>
            </div>
          <?php endforeach; ?>
        </section>

        <section class="trade-room__summary-card trade-room__summary-card--wide">
          <h4 class="trade-room__summary-head"><i class="bi bi-cash-stack"></i> وضعیت پرداخت اختلاف قیمت</h4>
          <?php foreach ($mtBoard['members'] as $m): $d = (float)($m['diff_amount'] ?? 0); ?>
            <div class="trade-room__meta-line trade-room__meta-line--compact" style="direction: ltr;">
              <span><?= h($m['user_name']) ?></span>
              <span class="trade-room__pill <?= ($d <= 0 || (int)($m['diff_paid'] ?? 0)) ? 'trade-room__pill--success' : 'trade-room__pill--warning' ?>">
                <?= $d <= 0 ? 'نیازی نیست' : ((int)($m['diff_paid'] ?? 0) ? 'پرداخت شده' : 'در انتظار') ?>
              </span>
            </div>
          <?php endforeach; ?>
        </section>
      </aside>

    <?php else: ?>
      <!-- Original 2-person trade flow -->
      <aside class="trade-room__timeline-column">
        <section class="trade-room__timeline-card">
        <h3 class="trade-room__card-title">
          <!-- <i class="bi bi-list-check"></i> -->
          وضعیت معامله
        </h3>
        <div class="trade-room__timeline">
          <?php foreach ($timelineItems as $item): ?>
            <?php $stepStatus = get_step_status($item['id'], $trade, $contract, $hasReview); ?>
            <?php $itemDate = get_step_datetime($item['id'], $trade, $contract, $hasReview); ?>
            <?php if ($stepStatus === 'current'): ?>
            <a href="?id=<?= $tradeId ?>&tab=<?= h($item['tab']) ?>" class="trade-room__timeline-item trade-room__timeline-item--<?= $stepStatus ?>" style="text-decoration: none; display: block;">
            <?php else: ?>
            <div class="trade-room__timeline-item trade-room__timeline-item--<?= $stepStatus ?> trade-room__timeline-item--readonly">
            <?php endif; ?>
              <div class="trade-room__timeline-dot"></div>
              <div class="trade-room__timeline-body">
                <div class="trade-room__timeline-title">
                  <?= h($item['title']) ?>
                </div>
                <?php if ($itemDate): ?>
                  <div class="trade-room__timeline-note"><?= persian_datetime($itemDate) ?></div>
                <?php endif; ?>
              </div>
            <?php if ($stepStatus === 'current'): ?>
            </a>
            <?php else: ?>
            </div>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="trade-room__timeline-card trade-room__support">
        <h3 class="trade-room__card-title">
          <i class="bi bi-headset"></i>
          نیاز به کمک دارید؟
        </h3>
        <p class="trade-room__muted">اگر در هر مرحله ابهام یا اختلافی داشتید، از پشتیبانی کمک بگیرید.</p>
        <div class="trade-room__cta-row">
          <a href="<?= APP_URL ?>/support" class="btn btn-primary w-100">تماس با پشتیبانی</a>
        </div>
      </section>
    </aside>

    <!-- Center Column: Main Content -->
    <div class="trade-room__content">

      <!-- Tabs -->
      <?php $tabIcons = [
        'chat'     => 'bi-chat-dots',
        'fee'      => 'bi-credit-card',
        'contract' => 'bi-file-earmark-text',
        'diff'     => 'bi-cash',
        'shipping' => 'bi-truck',
        'details'  => 'bi-box-seam',
        'final'    => 'bi-check-circle',
      ]; ?>
      <nav class="trade-room__tabs" aria-label="مراحل معامله">
        <?php foreach ($tabLabels as $tabKey => $label): ?>
          <a href="?id=<?= $tradeId ?>&tab=<?= h($tabKey) ?>" class="trade-room__tab <?= $tab === $tabKey ? 'trade-room__tab--active' : '' ?>">
            <i class="bi <?= $tabIcons[$tabKey] ?? 'bi-circle' ?> trade-room__tab-icon"></i>
            <span><?= h($label) ?></span>
          </a>
        <?php endforeach; ?>
      </nav>

      <!-- Tab Content -->
      <section class="trade-room__panel">
        <?php if ($tab === 'chat'): ?>
          <h3 class="trade-room__card-title trade-room__card-title--center">
            <i class="bi bi-lock"></i>
            گفتگوی امن
          </h3>
          <p class="trade-room__muted trade-room__muted--center">تمام پیام‌ها در محیط امن ذخیره می‌شوند و مبنای پیگیری معامله هستند.</p>

          <div class="trade-room__chat-list" id="trade-room-chat">
            <?php if (empty($messages)): ?>
              <div class="trade-room__chat-empty">هنوز پیامی ثبت نشده است.</div>
            <?php else: ?>
              <?php foreach ($messages as $msg): ?>
                <?php $isMine = (int)$msg['user_id'] === $uid; ?>
                <div class="trade-room__chat-item <?= $isMine ? 'trade-room__chat-item--mine' : 'trade-room__chat-item--other' ?>">
                  <?php if (!$isMine): ?>
                    <div class="trade-room__chat-avatar"><?= h(mb_substr($msg['user_name'], 0, 1)) ?></div>
                  <?php endif; ?>
                  <div class="trade-room__chat-bubble">
                    <div><?= h($msg['body']) ?></div>
                    <div class="trade-room__chat-meta"><?= h($msg['user_name']) ?> · <?= persian_date($msg['created_at']) ?></div>
                  </div>
                  <?php if ($isMine): ?>
                    <div class="trade-room__chat-avatar"><?= h(mb_substr($user['name'], 0, 1)) ?></div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <div class="trade-room__composer">
            <div class="trade-room__composer-tools">
              <button type="button" class="trade-room__tool-btn" title="فایل"><i class="bi bi-paperclip"></i></button>
              <button type="button" class="trade-room__tool-btn" title="گالری"><i class="bi bi-image"></i></button>
              <button type="button" class="trade-room__tool-btn" title="ویس"><i class="bi bi-mic"></i></button>
            </div>
            <form method="POST" class="trade-room__composer-input">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="send_message">
              <input type="text" name="body" placeholder="پیام خود را بنویسید..." autocomplete="off" required>
            </form>
            <button type="button" class="trade-room__send" onclick="this.parentNode.querySelector('form').submit()">
              <i class="bi bi-send"></i>
            </button>
          </div>

        <?php elseif ($tab === 'fee'): ?>
          <h3 class="trade-room__card-title"> 
            <i class="bi bi-credit-card"></i>
            پرداخت کارمزد
          </h3>
          <div class="trade-room__action">
            <h4>وضعیت کارمزد پلتفرم</h4>
            <p>هر طرف باید کارمزد خودش را جداگانه پرداخت کند.</p>
            <div class="trade-room__meta-box" style="margin: var(--sp-4) 0;">
              <div class="trade-room__meta-line">
                <span><?= h($trade['user_a_name']) ?> (کارمزد: <?= fmt_credit($feeA) ?>)</span>
                <span class="<?= trade_user_fee_paid($trade, true) ? 'trade-room__pill trade-room__pill--success' : 'trade-room__pill trade-room__pill--warning' ?>">
                  <?= trade_user_fee_paid($trade, true) ? 'پرداخت شده' : 'در انتظار' ?>
                </span>
              </div>
              <div class="trade-room__meta-line">
                <span><?= h($trade['user_b_name']) ?> (کارمزد: <?= fmt_credit($feeB) ?>)</span>
                <span class="<?= trade_user_fee_paid($trade, false) ? 'trade-room__pill trade-room__pill--success' : 'trade-room__pill trade-room__pill--warning' ?>">
                  <?= trade_user_fee_paid($trade, false) ? 'پرداخت شده' : 'در انتظار' ?>
                </span>
              </div>
            </div>
            <?php if ($feesFullyPaid): ?>
              <span class="trade-room__pill trade-room__pill--success">کارمزد هر دو طرف پرداخت شد</span>
            <?php elseif (!$myFeePaid): ?>
              <div class="trade-room__notice">کارمزد شما: <strong><?= fmt_credit($myFee) ?></strong></div>
              <form method="POST" class="trade-room__cta-row">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="pay_fee">
                <button type="submit" class="btn btn-primary w-100">
                  <i class="bi bi-check-circle"></i> پرداخت کارمزد
                </button>
              </form>
            <?php else: ?>
              <span class="trade-room__pill trade-room__pill--success">کارمزد شما پرداخت شده است</span>
              <div class="trade-room__notice">در انتظار پرداخت کارمزد توسط طرف مقابل هستیم.</div>
            <?php endif; ?>
          </div>

        <?php elseif ($tab === 'contract'): ?>
          <h3 class="trade-room__card-title">
            <i class="bi bi-file-earmark-text"></i>
            تایید قرارداد
          </h3>
          <?php if (!$feesFullyPaid): ?>
            <div class="trade-room__notice">ابتدا از تب «پرداخت کارمزد» هر دو طرف باید کارمزد خود را پرداخت کنند.</div>
          <?php else: ?>
          <div class="trade-room__action">
            <h4>امضای قرارداد</h4>
            <p>هر دو طرف باید قرارداد را امضا کنند تا تب ارسال فعال شود.</p>
            <div class="trade-room__meta-box">
              <div class="trade-room__meta-line">
                <span><?= h($trade['user_a_name']) ?></span>
                <span class="<?= !empty($contract['user_a_signed']) ? 'trade-room__pill trade-room__pill--success' : 'trade-room__pill trade-room__pill--warning' ?>">
                  <?= !empty($contract['user_a_signed']) ? 'امضا شده' : 'در انتظار امضا' ?>
                </span>
              </div>
              <div class="trade-room__meta-line">
                <span><?= h($trade['user_b_name']) ?></span>
                <span class="<?= !empty($contract['user_b_signed']) ? 'trade-room__pill trade-room__pill--success' : 'trade-room__pill trade-room__pill--warning' ?>">
                  <?= !empty($contract['user_b_signed']) ? 'امضا شده' : 'در انتظار امضا' ?>
                </span>
              </div>
            </div>
            <?php if (($isA && empty($contract['user_a_signed'])) || (!$isA && empty($contract['user_b_signed']))): ?>
              <div class="trade-room__cta-row">
                <form method="POST" class="w-100">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="sign_contract">
                  <button type="submit" class="btn btn-primary w-100">امضای قرارداد</button>
                </form>
              </div>
            <?php endif; ?>
          </div>
          <?php endif; ?>

        <?php elseif ($tab === 'diff'): ?>
          <h3 class="trade-room__card-title">
            <i class="bi bi-cash"></i>
            پرداخت اختلاف قیمت
          </h3>
          <div class="trade-room__action">
            <h4>اختلاف قیمت</h4>
            <?php if ($amountToPay <= 0): ?>
              <p>برای این معامله اختلاف قیمت در نظر گرفته نشده است.</p>
              <span class="trade-room__pill trade-room__pill--success">نیازی نیست</span>
            <?php elseif ($trade['diff_paid']): ?>
              <p>مابه‌التفاوت این معامله با موفقیت در امانت سواَپین ثبت شده است.</p>
              <span class="trade-room__pill trade-room__pill--success">پرداخت شده</span>
            <?php elseif ($iOwe): ?>
              <p>شما باید مبلغ <strong><?= fmt_credit($amountToPay) ?></strong> را برای ادامه معامله پرداخت کنید.</p>
              <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="pay_diff">
                <button type="submit" class="btn btn-accent w-100">پرداخت اختلاف قیمت</button>
              </form>
            <?php else: ?>
              <p>در حال انتظار برای پرداخت اختلاف قیمت توسط طرف مقابل هستیم.</p>
              <span class="trade-room__pill trade-room__pill--warning">در انتظار</span>
            <?php endif; ?>
          </div>

        <?php elseif ($tab === 'shipping'): ?>
          <h3 class="trade-room__card-title">
            <i class="bi bi-truck"></i>
            ارسال
          </h3>

          <?php if (!$contractSigned): ?>
            <div class="trade-room__notice">ابتدا از تب «تایید قرارداد» امضای هر دو طرف را تکمیل کنید.</div>
          <?php else: ?>
            <div class="trade-room__stack">
              <div class="trade-room__grid">
                <div class="trade-room__action">
                  <h4>زمان و روش ارسال شما</h4>
                  <?php
                    $myDate   = $isA ? ($trade['user_a_shipping_date'] ?? '') : ($trade['user_b_shipping_date'] ?? '');
                    $myTime   = $isA ? ($trade['user_a_shipping_time'] ?? '') : ($trade['user_b_shipping_time'] ?? '');
                    $myMethod = $isA ? ($trade['user_a_shipping_method'] ?? '') : ($trade['user_b_shipping_method'] ?? '');
                  ?>
                  <?php if ($myDate && $myTime && $myMethod): ?>
                    <p>اطلاعات ارسال شما ثبت شده است.</p>
                    <span class="trade-room__pill trade-room__pill--success"><?= persian_date($myDate) ?> · <?= h(substr($myTime, 0, 5)) ?></span>
                    <div class="fs-sm mt-2" style="color:var(--text-muted)">روش: <?= h(shipping_label($myMethod)) ?></div>
                  <?php else: ?>
                    <form method="POST" class="trade-room__stack">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="set_shipping">
                        <div class="form-group">
                            <label class="form-label">تاریخ ارسال (شمسی)</label>
                            <input type="text" name="shipping_date" class="form-control jalali-date-input"
                                   data-jdp data-jdp-only-date placeholder="۱۴۰۴/۰۴/۲۳" autocomplete="off" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">ساعت ارسال</label>
                            <input type="time" name="shipping_time" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-primary">ثبت زمان ارسال</button>
                    </form>
                  <?php endif; ?>
                </div>

                <div class="trade-room__action">
                  <h4>زمان و روش ارسال طرف مقابل</h4>
                  <?php
                    $theirDate   = $isA ? ($trade['user_b_shipping_date'] ?? '') : ($trade['user_a_shipping_date'] ?? '');
                    $theirTime   = $isA ? ($trade['user_b_shipping_time'] ?? '') : ($trade['user_a_shipping_time'] ?? '');
                    $theirMethod = $isA ? ($trade['user_b_shipping_method'] ?? '') : ($trade['user_a_shipping_method'] ?? '');
                  ?>
                  <?php if ($theirDate && $theirTime && $theirMethod): ?>
                    <p>اطلاعات ارسال طرف مقابل مشخص شده است.</p>
                    <span class="trade-room__pill trade-room__pill--success"><?= persian_date($theirDate) ?> · <?= h(substr($theirTime, 0, 5)) ?></span>
                    <div class="fs-sm mt-2" style="color:var(--text-muted)">روش: <?= h(shipping_label($theirMethod)) ?></div>
                  <?php else: ?>
                    <div class="trade-room__notice">هنوز طرف مقابل زمان و روش ارسال خودش را ثبت نکرده است.</div>
                  <?php endif; ?>
                </div>
              </div>

              <?php
                $statusLabels = [
                    'preparing' => 'در حال آماده‌سازی ارسال',
                    'shipped' => 'ارسال شده',
                    'dispute' => 'اختلاف داوری'
                ];
              ?>
              <div class="trade-room__grid">
                <div class="trade-room__action">
                  <h4>وضعیت ارسال شما</h4>
                  <?php if (!$shippingReady): ?>
                    <div class="trade-room__notice">بعد از ثبت زمان ارسال توسط هر دو طرف، این مرحله فعال می‌شود.</div>
                  <?php else: ?>
                    <?php
                      $myStatus = $isA ? ($trade['user_a_shipping_status'] ?? 'preparing') : ($trade['user_b_shipping_status'] ?? 'preparing');
                    ?>
                    <form method="POST" class="trade-room__stack">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="update_shipping_status">
                      <select name="shipping_status" class="form-control" onchange="this.form.submit()">
                        <?php foreach ($statusLabels as $key => $label): ?>
                          <option value="<?= $key ?>" <?= $myStatus === $key ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                      </select>
                    </form>
                  <?php endif; ?>
                </div>

                <div class="trade-room__action">
                  <h4>وضعیت ارسال طرف مقابل</h4>
                  <?php
                    $theirStatus = $isA ? ($trade['user_b_shipping_status'] ?? 'preparing') : ($trade['user_a_shipping_status'] ?? 'preparing');
                  ?>
                  <span class="trade-room__pill trade-room__pill--success"><?= $statusLabels[$theirStatus] ?? 'در حال آماده‌سازی ارسال' ?></span>
                </div>
              </div>

              <div class="trade-room__grid">
                <div class="trade-room__action">
                  <h4>کد رهگیری شما</h4>
                  <?php if ($trackingMine !== ''): ?>
                    <span class="trade-room__pill trade-room__pill--success"><?= h($trackingMine) ?></span>
                  <?php elseif (!$deliveredByMe): ?>
                    <div class="trade-room__notice">بعد از ثبت ارسال، کد رهگیری شما اینجا ثبت می‌شود.</div>
                  <?php else: ?>
                    <form method="POST" class="trade-room__stack">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="save_tracking">
                      <input type="text" name="tracking_code" class="form-control" placeholder="کد رهگیری را وارد کنید" required>
                      <button type="submit" class="btn btn-primary">ثبت کد رهگیری</button>
                    </form>
                  <?php endif; ?>
                </div>

                <div class="trade-room__action">
                  <h4>کد رهگیری طرف مقابل</h4>
                  <?php if ($trackingTheirs !== ''): ?>
                    <span class="trade-room__pill trade-room__pill--success"><?= h($trackingTheirs) ?></span>
                  <?php else: ?>
                    <div class="trade-room__notice">در انتظار ثبت کد رهگیری توسط طرف مقابل.</div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endif; ?>

        <?php elseif ($tab === 'details'): ?>
          <h3 class="trade-room__card-title">
            <i class="bi bi-box-seam"></i>
            دریافت
          </h3>

          <?php if ($trackingMine === '' || $trackingTheirs === ''): ?>
            <div class="trade-room__notice">ابتدا از تب «ارسال» کد رهگیری هر دو طرف را ثبت کنید.</div>
          <?php else: ?>
            <div class="trade-room__grid">
              <div class="trade-room__action">
                <h4>تایید دریافت شما</h4>
                <?php if ($receivedMine): ?>
                  <span class="trade-room__pill trade-room__pill--success">دریافت شما تایید شده است</span>
                <?php else: ?>
                  <p>اگر کالا را بدون مشکل تحویل گرفته‌اید، این مرحله را تایید کنید.</p>
                  <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="confirm_received">
                    <button type="submit" class="btn btn-primary">کالا را دریافت کردم</button>
                  </form>
                <?php endif; ?>
              </div>

              <div class="trade-room__action">
                <h4>تایید دریافت طرف مقابل</h4>
                <?php if ($receivedTheirs): ?>
                  <span class="trade-room__pill trade-room__pill--success">طرف مقابل هم دریافت را تایید کرده است</span>
                <?php else: ?>
                  <div class="trade-room__notice">هنوز طرف مقابل دریافت کالا را تایید نکرده است.</div>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>

        <?php elseif ($tab === 'final'): ?>
          <h3 class="trade-room__card-title">
            <i class="bi bi-check-circle"></i>
            تایید نهایی و ثبت نظر
          </h3>

          <?php if (!$receivedAll): ?>
            <div class="trade-room__notice">ابتدا از تب «دریافت» تایید دریافت توسط هر دو طرف را تکمیل کنید.</div>
          <?php elseif (!$completedTrade): ?>
            <div class="trade-room__action" style="margin-bottom: 20px;">
              <h4>تایید نهایی معامله</h4>
              <p>با تایید نهایی، معامله بسته می‌شود و سپرده آزاد خواهد شد.</p>
              <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="confirm_trade">
                <button type="submit" class="btn btn-accent">تایید نهایی معامله</button>
              </form>
            </div>
          <?php elseif ($hasReview): ?>
            <div class="trade-room__notice">امتیاز شما قبلاً ثبت شده است. ممنون از بازخوردتان.</div>
          <?php else: ?>
            <form method="POST" action="<?= APP_URL ?>/api/review.php" class="trade-room__stack" style="max-width:520px;">
              <?= csrf_field() ?>
              <input type="hidden" name="trade_id" value="<?= $tradeId ?>">
              <input type="hidden" name="to_user_id" value="<?= $otherId ?>">

              <div>
                <label class="form-label">امتیاز به معامله</label>
                <select name="trade_rating" class="form-control" required>
                  <option value="5">⭐⭐⭐⭐⭐ 5</option>
                  <option value="4">⭐⭐⭐⭐ 4</option>
                  <option value="3">⭐⭐⭐ 3</option>
                  <option value="2">⭐⭐ 2</option>
                  <option value="1">⭐ 1</option>
                </select>
              </div>

              <div>
                <label class="form-label">امتیاز به طرف مقابل</label>
                <select name="user_rating" class="form-control" required>
                  <option value="5">⭐⭐⭐⭐⭐ 5</option>
                  <option value="4">⭐⭐⭐⭐ 4</option>
                  <option value="3">⭐⭐⭐ 3</option>
                  <option value="2">⭐⭐ 2</option>
                  <option value="1">⭐ 1</option>
                </select>
              </div>

              <div>
                <label class="form-label">نظر شما</label>
                <textarea name="comment" rows="4" class="form-control" placeholder="توضیح کوتاه درباره تجربه معامله"></textarea>
              </div>

              <button type="submit" class="btn btn-primary">ثبت امتیاز</button>
            </form>
          <?php endif; ?>
        <?php endif; ?>
      </section>
    </div>

    <!-- Left Column: Details (30% width) -->
    <aside class="trade-room__details-column">
      <section class="trade-room__card trade-room__card--compact">
        <h3 class="trade-room__card-title">
          <!-- <i class="bi bi-receipt"></i> -->
          جزئیات معامله
        </h3>

        <!-- Products -->
        <div class="trade-room__product-card" style="margin-top: 41px;">
          <?php if ($myProduct['img']): ?>
            <img src="<?= h($myProduct['img']) ?>" alt="<?= h($myProduct['title']) ?>" class="trade-room__product-thumb">
          <?php else: ?>
            <div class="trade-room__product-thumb"><i class="bi bi-image"></i></div>
          <?php endif; ?>
          <div>
            <div class="trade-room__product-title"><?= h($myProduct['title']) ?></div>
            <div class="trade-room__product-meta">کالای شما · <?= $myProduct['val'] ? fmt_credit((float)$myProduct['val']) : 'بدون ارزش‌گذاری' ?></div>
          </div>
        </div>

        <div class="trade-room__swap-divider">
          <span class="trade-room__swap-divider-icon"><i class="bi bi-arrow-left-right"></i></span>
        </div>

        <div class="trade-room__product-card">
          <?php if ($otherProduct['img']): ?>
            <img src="<?= h($otherProduct['img']) ?>" alt="<?= h($otherProduct['title']) ?>" class="trade-room__product-thumb">
          <?php else: ?>
            <div class="trade-room__product-thumb"><i class="bi bi-image"></i></div>
          <?php endif; ?>
          <div>
            <div class="trade-room__product-title"><?= h($otherProduct['title']) ?></div>
            <div class="trade-room__product-meta">کالای طرف مقابل · <?= $otherProduct['val'] ? fmt_credit((float)$otherProduct['val']) : 'بدون ارزش‌گذاری' ?></div>
          </div>
        </div>
      </section>

      <section class="trade-room__summary-card trade-room__summary-card--wide">
        <h4 class="trade-room__summary-head">
           اختلاف قیمت
        </h4>
         <div class="trade-room__summary-line">
          <span class="trade-room__summary-label"></span>
          <span class="trade-room__summary-value"><?= $amountToPay > 0 ? fmt_credit($amountToPay) : 'بدون اختلاف' ?></span>
        </div>
      </section>

      <section class="trade-room__summary-card trade-room__summary-card--wide">
        <h4 class="trade-room__summary-head">
          کارمزد سواَپین(هرطرف)
        </h4>
        <div class="trade-room__meta-line trade-room__meta-line--compact" style="direction: ltr;">
          <span><?= h($trade['user_a_name']) ?></span>
          <span class="trade-room__summary-value"><?= fmt_credit($feeA) ?></span>
        </div>
        <div class="trade-room__meta-line trade-room__meta-line--compact" style="direction: ltr;">
          <span><?= h($trade['user_b_name']) ?></span>
          <span class="trade-room__summary-value"><?= fmt_credit($feeB) ?></span>
        </div>
      </section>

      <section class="trade-room__summary-card trade-room__summary-card--wide">
        <h4 class="trade-room__summary-head">
         وضعیت پرداخت کارمزد
        </h4>
        <div class="trade-room__meta-line trade-room__meta-line--compact" style="direction: ltr;">
          <span><?= h($trade['user_a_name']) ?></span>
          <span class="trade-room__pill <?= trade_user_fee_paid($trade, true) ? 'trade-room__pill--success' : 'trade-room__pill--warning' ?>">
            <?= trade_user_fee_paid($trade, true) ? 'پرداخت شده' : 'در انتظار' ?>
          </span>
        </div>
        <div class="trade-room__meta-line trade-room__meta-line--compact" style="direction: ltr;">
          <span><?= h($trade['user_b_name']) ?></span>
          <span class="trade-room__pill <?= trade_user_fee_paid($trade, false) ? 'trade-room__pill--success' : 'trade-room__pill--warning' ?>">
            <?= trade_user_fee_paid($trade, false) ? 'پرداخت شده' : 'در انتظار' ?>
          </span>
        </div>
      </section>

      <section class="trade-room__summary-card trade-room__summary-card--wide">
        <h4 class="trade-room__summary-head">
          <i class="bi bi-cash-stack"></i> وضعیت پرداخت اختلاف قیمت
        </h4>
        <?php if ($amountToPay <= 0): ?>
          <div class="trade-room__summary-note">برای این معامله اختلاف قیمت محاسبه نشده است.</div>
        <?php elseif ($trade['diff_paid']): ?>
          <div class="trade-room__summary-note" style="color: #16a34a;">اختلاف قیمت از قبل پرداخت شده است.</div>
        <?php else: ?>
          <div class="trade-room__summary-note">هنوز اختلاف قیمت پرداخت نشده است.</div>
        <?php endif; ?>
      </section>
    </aside>
    <?php endif; // End of original 2-person flow else block ?>
  </div>

  <?php if ((!$multiRoom || $multiRoom['status'] === 'cancelled') || ($multiRoom && $multiRoom['status'] === 'active')): ?>
    <?php
      if ($multiRoom) {
          $currentPaymentMethod = $mtMyMember['payment_method'] ?? '';
          $currentShippingMethod = $mtDeliverTrack ? ($mtDeliverTrack['shipping_method'] === 'in_person' ? 'swapin_secure' : $mtDeliverTrack['shipping_method']) : '';
          $canChangePayment = !$mtMyDiffPaid && $mtAllSigned && $mtMyDiff > 0;
          $canChangeShipping = !$mtMyShippingSet && $mtAllSigned && $mtDeliverTrack;
      } else {
          $currentPaymentMethod = $trade['selected_payment_method'] ?? '';
          $currentShippingMethod = $trade['selected_shipping_method'] ?? '';
          $feePaid = trade_fees_fully_paid($trade);
          $diffPaid = !empty($trade['diff_paid']);
          $contractSigned = !empty($contract['user_a_signed']) && !empty($contract['user_b_signed']);
          $shippingReady = trade_shipping_fully_scheduled($trade);
          $canChangePayment = !$diffPaid && $contractSigned && $amountToPay > 0;
          $canChangeShipping = !$shippingReady && $contractSigned;
      }
    ?>
  <div class="box-trade-room__layout">
    <form method="POST" class="pay-metod" id="paymentMethodForm" <?= !$canChangePayment ? 'data-disabled="true"' : '' ?>>
      <input type="hidden" name="action" value="select_payment_method">
      <?= csrf_field() ?>
      <h3 class="box-trade-room__title">تسویه اختلاف قیمت</h3>
      <div class="box-trade-room__cards">
        <label class="box-trade-room__card <?= $currentPaymentMethod === 'in_person' ? 'box-trade-room__card--selected' : '' ?>">
          <input type="radio" name="payment_method" value="in_person" class="box-trade-room__radio" <?= $currentPaymentMethod === 'in_person' ? 'checked' : '' ?> <?= !$canChangePayment ? 'disabled' : '' ?>>
          <div class="box-trade-room__card-icon">
            <i class="bi bi-handbag"></i>
          </div>
          <div class="box-trade-room__card-content">
            <h4>پرداخت در محل</h4>
            <p>هنگام تحویل کالا</p>
          </div>
          <div class="box-trade-room__card-check">
            <i class="bi bi-check-circle-fill"></i>
          </div>
        </label>
        <label class="box-trade-room__card <?= $currentPaymentMethod === 'bnpl' ? 'box-trade-room__card--selected' : '' ?>">
          <input type="radio" name="payment_method" value="bnpl" class="box-trade-room__radio" <?= $currentPaymentMethod === 'bnpl' ? 'checked' : '' ?> <?= !$canChangePayment ? 'disabled' : '' ?>>
          <div class="box-trade-room__card-icon">
            <i class="bi bi-calendar-check"></i>
          </div>
          <div class="box-trade-room__card-content">
            <h4>پرداخت اقساط BNPL</h4>
            <p>پرداخت در 4 قسط</p>
          </div>
          <div class="box-trade-room__card-check">
            <i class="bi bi-check-circle-fill"></i>
          </div>
        </label>
        <label class="box-trade-room__card <?= $currentPaymentMethod === 'cash' ? 'box-trade-room__card--selected' : '' ?>">
          <input type="radio" name="payment_method" value="cash" class="box-trade-room__radio" <?= $currentPaymentMethod === 'cash' ? 'checked' : '' ?> <?= !$canChangePayment ? 'disabled' : '' ?>>
          <div class="box-trade-room__card-icon">
            <i class="bi bi-wallet2"></i>
          </div>
          <div class="box-trade-room__card-content">
            <h4>پرداخت نقدی</h4>
            <p>کم کردن از کیف پول</p>
          </div>
          <div class="box-trade-room__card-check">
            <i class="bi bi-check-circle-fill"></i>
          </div>
        </label>
      </div>
    </form>
    <form method="POST" class="send-metod" id="shippingMethodForm" <?= !$canChangeShipping ? 'data-disabled="true"' : '' ?>>
      <input type="hidden" name="action" value="select_shipping_method">
      <?= csrf_field() ?>
      <h3 class="box-trade-room__title">روش ارسال</h3>
      <div class="box-trade-room__cards">
        <label class="box-trade-room__card <?= $currentShippingMethod === 'courier' ? 'box-trade-room__card--selected' : '' ?>">
          <input type="radio" name="shipping_method" value="courier" class="box-trade-room__radio" <?= $currentShippingMethod === 'courier' ? 'checked' : '' ?> <?= !$canChangeShipping ? 'disabled' : '' ?>>
          <div class="box-trade-room__card-icon">
            <i class="bi bi-motorcycle"></i>
          </div>
          <div class="box-trade-room__card-content">
            <h4>پیک فوری</h4>
            <p>ارسال سریع درون شهری</p>
          </div>
          <div class="box-trade-room__card-check">
            <i class="bi bi-check-circle-fill"></i>
          </div>
        </label>
        <label class="box-trade-room__card <?= $currentShippingMethod === 'post' ? 'box-trade-room__card--selected' : '' ?>">
          <input type="radio" name="shipping_method" value="post" class="box-trade-room__radio" <?= $currentShippingMethod === 'post' ? 'checked' : '' ?> <?= !$canChangeShipping ? 'disabled' : '' ?>>
          <div class="box-trade-room__card-icon">
            <i class="bi bi-envelope-paper"></i>
          </div>
          <div class="box-trade-room__card-content">
            <h4>پست پیشتاز</h4>
            <p>ارسال به سراسر کشور</p>
          </div>
          <div class="box-trade-room__card-check">
            <i class="bi bi-check-circle-fill"></i>
          </div>
        </label>
        <label class="box-trade-room__card <?= $currentShippingMethod === 'swapin_secure' ? 'box-trade-room__card--selected' : '' ?>">
          <input type="radio" name="shipping_method" value="swapin_secure" class="box-trade-room__radio" <?= $currentShippingMethod === 'swapin_secure' ? 'checked' : '' ?> <?= !$canChangeShipping ? 'disabled' : '' ?>>
          <div class="box-trade-room__card-icon">
            <i class="bi bi-shield-check"></i>
          </div>
          <div class="box-trade-room__card-content">
            <h4>تحویل حضوری</h4>
            <p>ارسال در محل</p>
          </div>
          <div class="box-trade-room__card-check">
            <i class="bi bi-check-circle-fill"></i>
          </div>
        </label>
      </div>
    </form>
  </div>
  
  <script>
    // Auto-submit when payment method is selected
    document.getElementById('paymentMethodForm').addEventListener('change', function() {
      if (this.hasAttribute('data-disabled') && this.getAttribute('data-disabled') === 'true') {
        return;
      }
      this.submit();
    });
    
    // Auto-submit when shipping method is selected
    document.getElementById('shippingMethodForm').addEventListener('change', function() {
      if (this.hasAttribute('data-disabled') && this.getAttribute('data-disabled') === 'true') {
        return;
      }
      this.submit();
    });
  </script>
  <?php endif; // End of payment/shipping methods condition ?>
</div>

<!-- Offers Modal -->
<div class="trade-room__modal-overlay" id="offersModal">
  <div class="trade-room__modal trade-room__modal--full">
    <div class="trade-room__modal-header">
      <h2 class="trade-room__modal-title">پیشنهادها</h2>
      <button type="button" class="trade-room__modal-close" data-close-modal="offersModal">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>
    <div class="trade-room__modal-body">
      <?php if (empty($receivedOffers)): ?>
        <div class="trade-room__chat-empty">هنوز پیشنهادی نیست</div>
      <?php else: ?>
        <div class="trade-room__offers-grid">
        <?php foreach ($receivedOffers as $offer):
          $statusColors = ['pending' => 'warning', 'accepted' => 'success', 'rejected' => 'danger', 'cancelled' => 'info', 'completed' => 'success'];
          $statusColor = $statusColors[$offer['status']] ?? 'info';
        ?>
          <div class="trade-room__card trade-room__offer-card">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:var(--sp-4);flex-wrap:wrap;">
              <div style="flex:1;min-width:0;">
                <div style="display:flex;align-items:center;gap:var(--sp-3);margin-bottom:var(--sp-3);">
                  <?= avatar_html($offer['from_avatar'] ?? null, $offer['from_name'], 'md') ?>
                  <div>
                    <div style="font-weight:700;font-size:1.0625rem"><?= h($offer['from_name']) ?></div>
                    <?php
                      $avgRating = DB::fetch('SELECT AVG(rating) as avg_r FROM reviews WHERE to_user_id = ?', [$offer['from_user_id']])['avg_r'] ?? 0;
                      if ($avgRating > 0):
                    ?>
                    <div class="fs-xs" style="color:var(--accent-dark)">
                      <i class="bi bi-star-fill"></i> <?= number_format((float)$avgRating, 1) ?>
                    </div>
                    <?php endif; ?>
                  </div>
                  <span class="trade-room__pill trade-room__pill--<?= $statusColor ?> fs-xs" style="margin-inline-start:auto"><?= offer_status_label($offer['status']) ?></span>
                </div>

                <div class="fs-sm mb-2" style="color:var(--text-muted)">
                  برای: <strong><?= h($offer['listing_title'] ?? '') ?></strong>
                </div>

                <?php if ($offer['offer_listing_title'] || (float)$offer['offer_credit'] > 0): ?>
                  <div style="background:rgba(0,174,239,.04);border:1px solid rgba(0,174,239,.15);border-radius:var(--radius-md);padding:var(--sp-4);margin-bottom:var(--sp-3);">
                    <div class="fs-xs" style="color:var(--text-muted);margin-bottom:var(--sp-2)">پیشنهاد:</div>
                    <?php if ($offer['offer_listing_title']): ?>
                      <div style="display:flex;align-items:center;gap:var(--sp-3);">
                        <?php if ($offer['offer_listing_thumb'] ?? false): ?>
                          <img src="<?= UPLOAD_URL . h($offer['offer_listing_thumb']) ?>" alt="<?= h($offer['offer_listing_title']) ?>" style="width:60px;height:60px;border-radius:var(--radius-md);object-fit:cover">
                        <?php endif; ?>
                        <div style="font-weight:600"><i class="bi bi-box"></i> <?= h($offer['offer_listing_title']) ?></div>
                      </div>
                    <?php endif; ?>
                    <?php if ((float)$offer['offer_credit'] > 0): ?>
                      <div class="fs-md mt-2" style="color:var(--primary);font-weight:700">
                        <i class="bi bi-wallet2"></i> + <?= fmt_credit((float)$offer['offer_credit']) ?>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>

                <?php if ($offer['message']): ?>
                  <div style="background:var(--bg);border-radius:var(--radius-md);padding:var(--sp-4);font-size:.9375rem;color:var(--text-secondary)">
                    <div class="fs-xs mb-2" style="color:var(--text-muted)">پیام پیشنهاد‌دهنده:</div>
                    "<?= h($offer['message']) ?>"
                  </div>
                <?php endif; ?>

                <div class="fs-xs mt-4" style="color:var(--text-muted)">
                  <i class="bi bi-clock"></i> <?= persian_datetime($offer['created_at']) ?>
                </div>
              </div>

              <?php if ($offer['status'] === 'pending'): ?>
                <!-- AI Negotiator (Secure Room Modal - Minimal) -->
                <div class="negotiator-card trade-room__negotiator" data-offer-id="<?= (int)$offer['id'] ?>" style="width:100%;margin-bottom:var(--sp-4);">
                  <button type="button" class="negotiator-card__run btn btn-outline w-100"
                          style="--btn-gap:8px;--btn-py:10px;font-size:.85rem;">
                    <i class="bi bi-robot" style="color:var(--primary)"></i>
                    <span class="negotiator-card__run-label">🤖 تحلیل هوشمند پیشنهاد</span>
                  </button>
                  <div class="negotiator-card__body" style="display:none;margin-top:var(--sp-3);">
                    <div class="negotiator-card__loader" style="display:none;">
                      <div class="spinner spinner--sm spinner--primary"></div>
                      <span>در حال تحلیل پیشنهاد…</span>
                    </div>
                    <div class="negotiator-card__error" style="display:none;color:var(--danger);font-size:.875rem;padding:8px 10px;background:rgba(220,38,38,.05);border:1px solid rgba(220,38,38,.12);border-radius:8px;"></div>
                    <div class="negotiator-card__result" style="display:none;"></div>
                  </div>
                </div>

                <div class="trade-room__offer-actions">
                  <form method="POST" class="mb-3" action="<?= APP_URL ?>/trades">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="accept">
                    <input type="hidden" name="offer_id" value="<?= $offer['id'] ?>">
                    <div class="form-group">
                      <label class="form-label">پیام پذیرش:</label>
                      <textarea name="message" class="form-control negotiator-target-textarea" rows="2" required placeholder="مثلاً: سلام! پیشنهاد شما را می‌پذیرم."></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                      <i class="bi bi-check-lg"></i> پذیرش
                    </button>
                  </form>
                  <form method="POST" action="<?= APP_URL ?>/trades">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="offer_id" value="<?= $offer['id'] ?>">
                    <div class="form-group">
                      <label class="form-label">پیام رد:</label>
                      <textarea name="message" class="form-control negotiator-target-textarea" rows="2" required placeholder="مثلاً: متشکرم، اما این بار نمی‌تونم."></textarea>
                    </div>
                    <button type="submit" class="btn btn-ghost w-100" style="color:var(--danger)">
                      <i class="bi bi-x-lg"></i> رد
                    </button>
                  </form>
                </div>
              <?php elseif ($offer['status'] === 'accepted'): ?>
                <?php
                  $relatedTrade = DB::fetch('SELECT id FROM trades WHERE offer_id = ? LIMIT 1', [$offer['id']]);
                  if ($relatedTrade):
                ?>
                  <div style="width:100%">
                    <a href="<?= APP_URL ?>/trades/view?id=<?= (int)$relatedTrade['id'] ?>" class="btn btn-primary w-100">
                      <i class="bi bi-shield-lock"></i> ورود به اتاق معامله
                    </a>
                  </div>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Mobile Steps Modal -->
<div class="trade-room__modal-overlay" id="mobileStepsModal">
  <div class="trade-room__modal trade-room__modal--full">
    <div class="trade-room__modal-header">
      <h2 class="trade-room__modal-title">مراحل معامله</h2>
      <button type="button" class="trade-room__modal-close" data-close-modal="mobileStepsModal">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>
    <div class="trade-room__modal-body">
      <div class="trade-room__mobile-steps-list">
        <?php foreach ($timelineItems as $index => $item):
          $stepStatus = get_step_status($item['id'], $trade, $contract, $hasReview);
          $isCurrent = $stepStatus === 'current';
          $stepClass = 'trade-room__mobile-step';
          if ($stepStatus === 'done') {
            $stepClass .= ' trade-room__mobile-step--done trade-room__mobile-step--readonly';
          } elseif ($isCurrent) {
            $stepClass .= ' trade-room__mobile-step--active';
          } else {
            $stepClass .= ' trade-room__mobile-step--readonly';
          }
        ?>
          <button type="button" class="<?= $stepClass ?>" data-step-tab="<?= h($item['tab']) ?>" <?= $isCurrent ? '' : 'disabled' ?>>
            <div class="trade-room__mobile-step-dot">
              <?php if ($stepStatus === 'done'): ?>
                <i class="bi bi-check-lg"></i>
              <?php else: ?>
                <?= $index + 1 ?>
              <?php endif; ?>
            </div>
            <div>
              <div style="font-weight:800;"><?= h($item['title']) ?></div>
              <?php $itemDate = get_step_datetime($item['id'], $trade, $contract, $hasReview); ?>
              <?php if ($itemDate): ?>
                <div class="trade-room__muted" style="font-size:0.75rem;margin-top:2px;"><?= persian_datetime($itemDate) ?></div>
              <?php endif; ?>
            </div>
          </button>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- Step Detail Modal -->
<div class="trade-room__modal-overlay" id="mobileStepDetailModal">
  <div class="trade-room__modal trade-room__modal--full">
    <div class="trade-room__modal-header">
      <h2 class="trade-room__modal-title">مرحله معامله</h2>
      <button type="button" class="trade-room__modal-close" data-close-modal="mobileStepDetailModal">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>
    <div class="trade-room__modal-body">
      <div id="mobileStepDetailContent"></div>
      <div style="margin-top:18px;text-align:right;">
        <button type="button" class="btn btn-outline" data-close-modal="mobileStepDetailModal">بستن</button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  // Modal handlers
  function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
      modal.classList.add('trade-room__modal-overlay--open');
      document.body.style.overflow = 'hidden';
    }
  }

  function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
      modal.classList.remove('trade-room__modal-overlay--open');
      document.body.style.overflow = '';
    }
  }

  // Open buttons
  const openOffersBtn = document.getElementById('openOffersModal');
  if (openOffersBtn) {
    openOffersBtn.addEventListener('click', () => openModal('offersModal'));
  }

  const openMobileStepsBtn = document.getElementById('openMobileStepsModal');
  if (openMobileStepsBtn) {
    openMobileStepsBtn.addEventListener('click', () => openModal('mobileStepsModal'));
  }

  document.querySelectorAll('.trade-room__mobile-step[data-step-tab]').forEach(btn => {
    btn.addEventListener('click', () => {
      const tab = btn.getAttribute('data-step-tab');
      if (!tab) return;
      const params = new URLSearchParams(window.location.search);
      params.set('tab', tab);
      params.set('mobile_step', '1');
      window.location.search = params.toString();
    });
  });

  const mobileStepDetailModal = document.getElementById('mobileStepDetailModal');
  const mobileStepDetailContent = document.getElementById('mobileStepDetailContent');
  if (mobileStepDetailModal && mobileStepDetailContent) {
    const params = new URLSearchParams(window.location.search);
    if (params.get('mobile_step') === '1') {
      const panel = document.querySelector('.trade-room__panel');
      if (panel) {
        mobileStepDetailContent.innerHTML = panel.innerHTML;
      }
      openModal('mobileStepDetailModal');
      params.delete('mobile_step');
      window.history.replaceState(null, '', window.location.pathname + '?' + params.toString());
    }
  }

  // Close buttons
  document.querySelectorAll('[data-close-modal]').forEach(btn => {
    btn.addEventListener('click', (e) => {
      closeModal(e.currentTarget.getAttribute('data-close-modal'));
    });
  });

  // Close on overlay click
  document.querySelectorAll('.trade-room__modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) {
        closeModal(overlay.id);
      }
    });
  });

  /* ==========================================================
     AI Negotiator (Offers Modal in Secure Trade Room)
     ========================================================== */
  var csrf  = '<?= json_encode(csrf_token()) ?>';
  var apiBase = '<?= APP_URL ?>/api';

  function negoFmt(n) {
    if (!n && n !== 0) return '۰';
    n = Number(n) || 0;
    return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }
  function negoEsc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }
  function negoAssessCls(a) {
    if (a === 'favorable') return 'success';
    if (a === 'unfavorable') return 'danger';
    return 'warning';
  }
  function negoAssessLbl(a) {
    if (a === 'favorable') return 'به نفع شما';
    if (a === 'unfavorable') return 'نامناسب';
    return 'متعادل';
  }

  function negoResultHtml(offerId, result, fromRule, aiErr, rateLimited) {
    if (!result) return '';
    var r = result;
    var html = '';
    html += '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px">';
    html += '<span class="badge badge-' + negoAssessCls(r.assessment) + '" style="font-size:.75rem">' + negoAssessLbl(r.assessment) + '</span>';
    if (fromRule)
      html += '<span class="badge badge-info fs-xs"><i class="bi bi-cpu"></i> قاعده‌محور</span>';
    else
      html += '<span class="badge badge-success fs-xs"><i class="bi bi-stars"></i> AI</span>';
    if (rateLimited)
      html += '<span class="badge badge-warning fs-xs"><i class="bi bi-exclamation-triangle"></i> محدودیت</span>';
    html += '</div>';

    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px">';
    html += '<div style="background:rgba(0,174,239,.05);border:1px solid rgba(0,174,239,.15);border-radius:10px;padding:8px 10px">';
    html += '<div class="fs-xs" style="color:var(--text-muted)">کالای شما</div>';
    html += '<div style="font-weight:700;font-size:.9375rem">' + negoFmt(r.your_value) + ' <span class="fs-xs" style="color:var(--text-muted);font-weight:500">تومان</span></div>';
    html += '</div>';
    html += '<div style="background:rgba(126,87,194,.06);border:1px solid rgba(126,87,194,.15);border-radius:10px;padding:8px 10px">';
    html += '<div class="fs-xs" style="color:var(--text-muted)">کالای طرف</div>';
    html += '<div style="font-weight:700;font-size:.9375rem">' + negoFmt(r.their_value) + ' <span class="fs-xs" style="color:var(--text-muted);font-weight:500">تومان</span></div>';
    html += '</div>';
    html += '</div>';

    if (r.difference) {
      html += '<div style="background:rgba(126,87,194,.06);border-radius:10px;padding:8px 10px;margin-bottom:8px;font-size:.875rem">';
      html += '<div style="display:flex;justify-content:space-between"><span style="color:var(--text-secondary)">تفاوت:</span><strong>' + (r.difference > 0 ? '−' : '+') + ' ' + negoFmt(Math.abs(r.difference)) + ' تومان</strong></div>';
      if (r.suggested_cash_difference > 0)
        html += '<div style="display:flex;justify-content:space-between;margin-top:4px"><span style="color:var(--text-secondary)">پیشنهاد مابه‌التفاوت:</span><strong style="color:var(--primary)">' + negoFmt(r.suggested_cash_difference) + ' تومان</strong></div>';
      html += '</div>';
    }
    if (r.reason)
      html += '<div style="background:var(--bg);border-radius:10px;padding:8px 10px;font-size:.8125rem;color:var(--text-secondary);line-height:1.9;margin-bottom:10px">' + negoEsc(r.reason) + '</div>';
    if (aiErr && !fromRule)
      html += '<div class="alert alert-warning fs-xs mb-2" style="padding:6px 10px"><i class="bi bi-exclamation-triangle"></i> AI نامعتبر بود؛ از قاعده‌محور استفاده شد.</div>';

    if (r.suggested_replies && r.suggested_replies.length) {
      html += '<div class="fs-xs" style="font-weight:700;margin-bottom:6px">پاسخ‌های پیشنهادی (کلیک → داخل کادر پیام):</div>';
      html += '<div style="display:flex;flex-direction:column;gap:6px">';
      var tones = [{label:'دوستانه', icon:'bi-heart', cls:'success'},
                   {label:'مذاکره‌گرا', icon:'bi-chat-dots', cls:'primary'},
                   {label:'قطعی', icon:'bi-shield-check', cls:'danger'}];
      r.suggested_replies.forEach(function(txt, i) {
        var tone = tones[i] || tones[0];
        html += '<button type="button" class="btn btn-sm offer-negotiator-reply-btn" ' +
          'data-offer-id="' + offerId + '" ' +
          'data-reply="' + negoEsc(txt).replace(/"/g,'&quot;') + '" ' +
          'style="border:1px dashed var(--border);background:#fff;justify-content:space-between;text-align:right;padding:8px 10px;gap:6px;border-radius:10px;font-size:.8125rem;color:var(--text);line-height:1.7">';
        html += '<span style="display:flex;align-items:center;gap:6px"><i class="bi ' + tone.icon + '" style="color:var(--' + tone.cls + ')"></i> <strong style="color:var(--' + tone.cls + ');font-size:.75rem">' + tone.label + '</strong></span>';
        html += '<span style="flex:1;text-align:right;color:var(--text-secondary);max-width:75%;overflow-wrap:anywhere">' + negoEsc(txt) + '</span>';
        html += '<i class="bi bi-clipboard" style="color:var(--text-muted)"></i>';
        html += '</button>';
      });
      html += '</div>';
      html += '<div class="fs-xs mt-2" style="color:var(--text-muted)"><i class="bi bi-info-circle"></i> پیام را هنوز ارسال نکرده‌اید؛ در انتها حتماً «پذیرش» یا «رد» را بزنید.</div>';
    }
    return html;
  }

  function negoRun(btn, offerId) {
    var card = btn.closest('.negotiator-card');
    if (!card) return;
    var body   = card.querySelector('.negotiator-card__body');
    var loader = card.querySelector('.negotiator-card__loader');
    var errEl  = card.querySelector('.negotiator-card__error');
    var resEl  = card.querySelector('.negotiator-card__result');
    var lbl    = btn.querySelector('.negotiator-card__run-label');

    btn.disabled = true;
    if (body)   body.style.display = '';
    if (resEl) { resEl.style.display = 'none'; resEl.innerHTML = ''; }
    if (errEl) { errEl.style.display = 'none'; errEl.textContent = ''; }
    if (loader) loader.style.display = '';
    if (lbl) lbl.textContent = 'در حال تحلیل…';

    var url = apiBase + '/negotiator_analyze.php?offer_id=' + encodeURIComponent(offerId) + '&_=' + Date.now();
    fetch(url, { method:'GET', headers:{ 'X-CSRF-Token': csrf, 'Accept':'application/json' }, credentials:'same-origin' })
      .then(function(res) { if (!res.ok) throw new Error('HTTP ' + res.status); return res.json(); })
      .then(function(data) {
        btn.disabled = false;
        if (loader) loader.style.display = 'none';
        if (!data || !data.ok) {
          if (errEl) { errEl.textContent = 'تحلیل هوشمند در دسترس نیست. دکمه‌های پذیرش/رد همچنان فعال هستند.'; errEl.style.display = ''; }
          if (lbl) lbl.textContent = '🔄 تلاش مجدد';
          return;
        }
        if (lbl) lbl.textContent = '🔄 تحلیل مجدد';
        if (resEl) { resEl.innerHTML = negoResultHtml(offerId, data.result || null, !!data.from_rule_based, data.ai_error || null, !!data.rate_limited); resEl.style.display = ''; }
        wireOfferReplyBtns();
      })
      .catch(function() {
        btn.disabled = false;
        if (loader) loader.style.display = 'none';
        if (errEl) { errEl.textContent = 'تحلیل هوشمند در دسترس نیست. دکمه‌های پذیرش/رد همچنان فعال هستند.'; errEl.style.display = ''; }
        if (lbl) lbl.textContent = '🔄 تلاش مجدد';
      });
  }

  function negoFindTextarea(card, preferAccept) {
    var container = card.parentElement;
    var selector = preferAccept ? 'textarea[name="message"]:first-of-type' : 'textarea[name="message"]:last-of-type';
    var first = container.querySelector('textarea.negotiator-target-textarea');
    return first || container.querySelector(selector);
  }

  function wireOfferNegotiator() {
    document.querySelectorAll('.trade-room__negotiator .negotiator-card__run').forEach(function(btn) {
      if (btn.dataset.wired) return;
      btn.dataset.wired = '1';
      btn.addEventListener('click', function() {
        var card = btn.closest('.negotiator-card');
        var offerId = card ? card.dataset.offerId : null;
        if (!offerId) return;
        negoRun(btn, offerId);
      });
    });
  }
  function wireOfferReplyBtns() {
    document.querySelectorAll('.offer-negotiator-reply-btn').forEach(function(btn) {
      if (btn.dataset.wired) return;
      btn.dataset.wired = '1';
      btn.addEventListener('click', function() {
        var reply = btn.dataset.reply || '';
        var prefAccept = (reply.indexOf('پذیر') !== -1 || reply.indexOf('مناسبه') !== -1 || reply.indexOf('ادامه') !== -1 || reply.indexOf('توافق') !== -1);
        var offerId = btn.dataset.offerId;
        var card = offerId ? document.querySelector('.trade-room__negotiator[data-offer-id="' + offerId + '"]') : btn.closest('.negotiator-card');
        var ta = card ? negoFindTextarea(card, prefAccept) : null;
        if (ta) {
          ta.value = reply;
          ta.focus();
          ta.style.boxShadow = '0 0 0 3px rgba(8,27,69,.1)';
          setTimeout(function() { ta.style.boxShadow = ''; }, 900);
        }
      });
    });
  }
  wireOfferNegotiator();
  wireOfferReplyBtns();
});
</script>

<?php render_user_panel_close(); ?>
<?php render_panel_scripts(); ?>

<!-- Persian (Jalali) Date Picker -->
<link rel="stylesheet" href="https://unpkg.com/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.css">
<script src="https://unpkg.com/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  if (typeof jalaliDatepicker !== 'undefined') {
    jalaliDatepicker.startWatch({
      minDate: 'today',
      time: false,
      autoHide: true,
      separatorChars: { date: '/' },
      zIndex: 9999,
    });
  }
});
</script>
<?php render_footer(); ?>
