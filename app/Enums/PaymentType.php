<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which portion of a booking a Payment row covers (PRD §6.4.3 / §9):
 *  - Full     guest paid the full total at checkout
 *  - Deposit  guest paid the hold deposit (balance owed at check-in)
 *  - Balance  guest paid the remaining balance after a deposit
 *  - Invoice  manual off-Paystack receipt front desk booked against a
 *             specific Invoice (cash, transfer, POS). Kept distinct so
 *             the balance-reminder job doesn't treat these as the
 *             original balance-due payment.
 *  - PosSale  standalone point-of-sale receipt (bar, kitchen, laundry,
 *             spa, gift shop). booking_id is nullable — walk-in sales
 *             have no booking. When a sale is charged-to-room the
 *             Payment row's booking_id is set AND the BookingCharge
 *             carries the outstanding amount until the room folio is
 *             settled.
 *  - Extra    guest paid for an in-room-portal add-on (extend stay,
 *             room-service order paid by card, reserve another room).
 *             Kept distinct from Balance so the balance-reminder job
 *             + accountant splits don't get confused.
 *  - CorporateAr  money the corp remitted to settle their AR. Written
 *             alongside the canonical CorporatePayment row so the
 *             tenant-wide Payments page + KPIs see the cash. booking_id
 *             is NULL, corporate_id + corporate_payment_id are set.
 */
enum PaymentType: string
{
    case Full = 'full';
    case Deposit = 'deposit';
    case Balance = 'balance';
    case Invoice = 'invoice';
    case PosSale = 'pos_sale';
    case Extra = 'extra';
    case CorporateAr = 'corporate_ar';
}
