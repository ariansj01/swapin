#!/usr/bin/env python3
"""Idempotently build aggregate, schema-verified Swaapin analytics dashboards."""

import json
from pathlib import Path

from metabase_api import MetabaseAPI

api = MetabaseAPI()
state_path = Path('/opt/swaapin-metabase/dashboard-state.json')
state = json.loads(state_path.read_text()) if state_path.exists() else {'dashboards': {}, 'cards': {}}


def save():
    state_path.write_text(json.dumps(state, indent=2, ensure_ascii=False) + '\n')
    state_path.chmod(0o600)


def card(name, sql, display='table', description=None):
    return {'name': name, 'sql': sql.strip(), 'display': display, 'description': description}


dashboards = [
    ('Swaapin — Executive Overview', 'Verified aggregate management metrics. Successful payments are gross volume, not net revenue. User activity uses last_seen.', [
        card('Executive KPIs', """SELECT
          (SELECT COUNT(*) FROM users) AS total_users,
          (SELECT COUNT(*) FROM users WHERE DATE(created_at)=CURDATE()) AS new_users_today,
          (SELECT COUNT(*) FROM users WHERE created_at >= NOW()-INTERVAL 7 DAY) AS new_users_7d,
          (SELECT COUNT(*) FROM users WHERE created_at >= NOW()-INTERVAL 30 DAY) AS new_users_30d,
          (SELECT COUNT(*) FROM users WHERE last_seen >= NOW()-INTERVAL 30 DAY) AS active_users_30d_approx,
          (SELECT COUNT(DISTINCT user_id) FROM listings WHERE status='active') AS active_sellers,
          (SELECT COUNT(*) FROM listings) AS total_listings,
          (SELECT COUNT(*) FROM listings WHERE status='active') AS active_listings,
          (SELECT COUNT(*) FROM listings WHERE created_at >= NOW()-INTERVAL 7 DAY) AS new_listings_7d,
          (SELECT COUNT(*) FROM trade_offers) AS total_offers,
          (SELECT COUNT(*) FROM trade_offers WHERE created_at >= NOW()-INTERVAL 7 DAY) AS offers_7d,
          (SELECT COUNT(*) FROM trade_offers WHERE status='accepted') AS accepted_offers,
          ROUND(100*(SELECT COUNT(*) FROM trade_offers WHERE status='accepted')/NULLIF((SELECT COUNT(*) FROM trade_offers),0),1) AS offer_acceptance_pct,
          (SELECT COUNT(*) FROM trades) AS trades,
          (SELECT COUNT(*) FROM trades WHERE status='completed') AS completed_trades,
          ROUND(100*(SELECT COUNT(*) FROM trades WHERE status='completed')/NULLIF((SELECT COUNT(*) FROM trades),0),1) AS trade_completion_pct,
          (SELECT COUNT(*) FROM payments WHERE status='success') AS successful_payments,
          ROUND(100*(SELECT COUNT(*) FROM payments WHERE status='success')/NULLIF((SELECT COUNT(*) FROM payments),0),1) AS payment_success_pct,
          (SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='success') AS successful_payment_volume"""),
        card('User growth by day', 'SELECT DATE(created_at) AS day, COUNT(*) AS users FROM users GROUP BY DATE(created_at) ORDER BY day', 'line'),
        card('Listing growth by day', 'SELECT DATE(created_at) AS day, COUNT(*) AS listings FROM listings GROUP BY DATE(created_at) ORDER BY day', 'line'),
        card('Offer trend by day', 'SELECT DATE(created_at) AS day, COUNT(*) AS offers FROM trade_offers GROUP BY DATE(created_at) ORDER BY day', 'line'),
        card('Trade trend by day', 'SELECT DATE(created_at) AS day, COUNT(*) AS trades FROM trades GROUP BY DATE(created_at) ORDER BY day', 'line'),
        card('Successful payment volume by day', "SELECT DATE(created_at) AS day, SUM(amount) AS volume FROM payments WHERE status='success' GROUP BY DATE(created_at) ORDER BY day", 'line'),
        card('Top categories', 'SELECT c.name AS category, COUNT(*) AS listings FROM listings l JOIN categories c ON c.id=l.category_id GROUP BY c.id,c.name ORDER BY listings DESC LIMIT 15', 'bar'),
        card('Top cities by listings', "SELECT COALESCE(NULLIF(city,''),'Unknown') AS city, COUNT(*) AS listings FROM listings GROUP BY city ORDER BY listings DESC LIMIT 15", 'bar'),
        card('Operational funnel — distinct people', """SELECT stage, people, ROUND(100*people/NULLIF((SELECT COUNT(*) FROM users),0),1) AS pct_of_registered,
          ROUND(100*people/NULLIF(LAG(people) OVER (ORDER BY ord),0),1) AS population_ratio_to_previous_pct
          FROM (SELECT 1 AS ord,'Registered users' AS stage,COUNT(*) AS people FROM users
          UNION ALL SELECT 2,'Listing creators',COUNT(DISTINCT user_id) FROM listings
          UNION ALL SELECT 3,'Offer participants',COUNT(DISTINCT person_id) FROM (SELECT from_user_id AS person_id FROM trade_offers UNION SELECT l.user_id FROM trade_offers o JOIN listings l ON l.id=o.listing_id) p
          UNION ALL SELECT 4,'Conversation participants',COUNT(DISTINCT person_id) FROM (SELECT user_a_id AS person_id FROM user_conversations UNION SELECT user_b_id FROM user_conversations) q
          UNION ALL SELECT 5,'Trade participants',COUNT(DISTINCT person_id) FROM (SELECT user_a_id AS person_id FROM trades UNION SELECT user_b_id FROM trades) r
          UNION ALL SELECT 6,'Completed trade participants',COUNT(DISTINCT person_id) FROM (SELECT user_a_id AS person_id FROM trades WHERE status='completed' UNION SELECT user_b_id FROM trades WHERE status='completed') s) f ORDER BY ord""", description='Operational stage populations, not a cohort visitor funnel. Stage populations can differ and are not causal conversions. No user_events table exists.'),
        card('Operational stage timing', """SELECT 'Signup to first listing' AS transition,COUNT(*) AS linked_people,ROUND(AVG(TIMESTAMPDIFF(HOUR,u.created_at,l.first_at)),1) AS avg_hours
          FROM users u JOIN (SELECT user_id,MIN(created_at) AS first_at FROM listings GROUP BY user_id) l ON l.user_id=u.id WHERE l.first_at>=u.created_at
          UNION ALL SELECT 'First listing to first offer by creator',COUNT(*),ROUND(AVG(TIMESTAMPDIFF(HOUR,l.first_at,o.first_at)),1)
          FROM (SELECT user_id,MIN(created_at) AS first_at FROM listings GROUP BY user_id) l JOIN (SELECT from_user_id,MIN(created_at) AS first_at FROM trade_offers GROUP BY from_user_id) o ON o.from_user_id=l.user_id WHERE o.first_at>=l.first_at
          UNION ALL SELECT 'First offer to first listing conversation',COUNT(*),ROUND(AVG(TIMESTAMPDIFF(HOUR,o.first_at,c.first_at)),1)
          FROM (SELECT from_user_id,MIN(created_at) AS first_at FROM trade_offers GROUP BY from_user_id) o JOIN (SELECT user_a_id AS user_id,MIN(created_at) AS first_at FROM user_conversations GROUP BY user_a_id UNION ALL SELECT user_b_id,MIN(created_at) FROM user_conversations GROUP BY user_b_id) c ON c.user_id=o.from_user_id WHERE c.first_at>=o.first_at
          UNION ALL SELECT 'Trade start to completion',COUNT(*),ROUND(AVG(TIMESTAMPDIFF(HOUR,created_at,completed_at)),1) FROM trades WHERE status='completed' AND completed_at>=created_at""", description='Observed paired timestamps only; not visitor tracking or causal cohort conversion.'),
        card('Full product funnel — instrumentation required', """SELECT 'Visitor' AS stage,'page_view' AS required_event,'NOT_INSTRUMENTED' AS status,CAST(NULL AS UNSIGNED) AS event_count
          UNION ALL SELECT 'Signup','signup','NOT_INSTRUMENTED',NULL
          UNION ALL SELECT 'Listing','listing_create','NOT_INSTRUMENTED',NULL
          UNION ALL SELECT 'Match','match_view','NOT_INSTRUMENTED',NULL
          UNION ALL SELECT 'Offer View','offer_view','NOT_INSTRUMENTED',NULL
          UNION ALL SELECT 'Offer','offer_create','NOT_INSTRUMENTED',NULL
          UNION ALL SELECT 'Negotiation','chat_start','NOT_INSTRUMENTED',NULL
          UNION ALL SELECT 'Agreement','agreement_accept','NOT_INSTRUMENTED',NULL
          UNION ALL SELECT 'Successful Swap','transaction_complete','NOT_INSTRUMENTED',NULL""", description='Design placeholder only. No user_events table or historical event backfill exists. Counts, conversion and durations are intentionally NULL.'),
    ]),
    ('Swaapin — Users & Growth', 'Approximate activity based on last_seen. No event-based DAU/WAU/MAU or retention is claimed.', [
        card('Users, activity and onboarding', """SELECT COUNT(*) AS total_users,
          SUM(created_at>=NOW()-INTERVAL 1 DAY) AS new_24h,
          SUM(created_at>=NOW()-INTERVAL 7 DAY) AS new_7d,
          SUM(created_at>=NOW()-INTERVAL 30 DAY) AS new_30d,
          SUM(last_seen>=NOW()-INTERVAL 1 DAY) AS dau_approx,
          SUM(last_seen>=NOW()-INTERVAL 7 DAY) AS wau_approx,
          SUM(last_seen>=NOW()-INTERVAL 30 DAY) AS mau_approx,
          SUM(last_seen IS NULL OR last_seen<NOW()-INTERVAL 30 DAY) AS inactive_30d_approx,
          SUM(onboarding_completed=1) AS onboarding_complete,
          ROUND(100*SUM(onboarding_completed=1)/NULLIF(COUNT(*),0),1) AS onboarding_pct,
          SUM(kyc_manual_review=1) AS kyc_manual_review FROM users"""),
        card('New users by week', 'SELECT DATE(DATE_SUB(created_at, INTERVAL WEEKDAY(created_at) DAY)) AS week_start, COUNT(*) AS users FROM users GROUP BY week_start ORDER BY week_start', 'line'),
        card('New users by month', "SELECT DATE_FORMAT(created_at,'%Y-%m-01') AS month_start, COUNT(*) AS users FROM users GROUP BY month_start ORDER BY month_start", 'line'),
        card('Users by city', "SELECT COALESCE(NULLIF(city,''),'Unknown') AS city,COUNT(*) AS users FROM users GROUP BY city ORDER BY users DESC LIMIT 25", 'bar'),
        card('Seller and provider types', "SELECT COALESCE(seller_type,'Unknown') AS seller_type,COALESCE(provider_type,'Unknown') AS provider_type,COUNT(*) AS users FROM users GROUP BY seller_type,provider_type ORDER BY users DESC"),
        card('KYC status and risk', "SELECT COALESCE(kyc_status,'Unknown') AS kyc_status,COALESCE(kyc_risk_level,'Unknown') AS risk_level,COUNT(*) AS users FROM users GROUP BY kyc_status,kyc_risk_level ORDER BY users DESC"),
        card('Primary goals', "SELECT COALESCE(primary_goal,'Unknown') AS primary_goal,COUNT(*) AS users FROM users GROUP BY primary_goal ORDER BY users DESC", 'bar'),
        card('Subscription plans', "SELECT COALESCE(subscription_plan,'Unknown') AS plan,COUNT(*) AS users FROM users GROUP BY subscription_plan ORDER BY users DESC", 'bar'),
    ]),
    ('Swaapin — Listings & Supply', 'Actual listing status, review status and mode values are shown without invented labels.', [
        card('Listing supply KPIs', """SELECT COUNT(*) AS total_listings,
          SUM(status='active') AS active_listings,
          SUM(status='traded') AS traded_listings,
          SUM(status='expired') AS expired_listings,
          SUM(status='deleted') AS deleted_listings,
          SUM(review_status='approved') AS approved,
          SUM(review_status='pending') AS pending_review,
          SUM(review_status='rejected') AS rejected,
          ROUND(AVG(estimated_value),2) AS avg_estimated_value,
          ROUND(AVG(sell_price),2) AS avg_sell_price,
          SUM(views) AS total_views,
          ROUND(AVG(views),1) AS avg_views_per_listing,
          SUM(ai_reviewed=1) AS ai_reviewed,
          ROUND(100*SUM(ai_reviewed=1)/NULLIF(COUNT(*),0),1) AS ai_reviewed_pct,
          ROUND(AVG(ai_confidence),2) AS avg_ai_confidence FROM listings"""),
        card('Listings by status and review', "SELECT COALESCE(status,'Unknown') AS status,COALESCE(review_status,'Unknown') AS review_status,COUNT(*) AS listings FROM listings GROUP BY status,review_status ORDER BY listings DESC"),
        card('Listings by mode', "SELECT COALESCE(listing_mode,'Unknown') AS listing_mode,COUNT(*) AS listings FROM listings GROUP BY listing_mode ORDER BY listings DESC", 'bar'),
        card('Listings by condition', "SELECT COALESCE(`condition`,'Unknown') AS item_condition,COUNT(*) AS listings FROM listings GROUP BY `condition` ORDER BY listings DESC", 'bar'),
        card('Listings by category', 'SELECT c.name AS category,COUNT(*) AS listings,SUM(l.views) AS views FROM listings l LEFT JOIN categories c ON c.id=l.category_id GROUP BY c.id,c.name ORDER BY listings DESC LIMIT 25'),
        card('Listings by city', "SELECT COALESCE(NULLIF(city,''),'Unknown') AS city,COUNT(*) AS listings FROM listings GROUP BY city ORDER BY listings DESC LIMIT 25", 'bar'),
        card('AI suggestions for listings', "SELECT COALESCE(ai_suggestion,'Unknown') AS suggestion,COUNT(*) AS listings,ROUND(AVG(ai_confidence),2) AS avg_confidence FROM listings WHERE ai_reviewed=1 GROUP BY ai_suggestion ORDER BY listings DESC"),
        card('Listing creation trend', 'SELECT DATE(created_at) AS day,COUNT(*) AS listings FROM listings GROUP BY DATE(created_at) ORDER BY day', 'line'),
    ]),
    ('Swaapin — Offers & Swap Conversion', 'Offer statuses come from Production: accepted and pending at implementation time. Conversion uses direct offer_id joins.', [
        card('Offer conversion KPIs', """SELECT (SELECT COUNT(*) FROM trade_offers) AS offers,
          (SELECT COUNT(*) FROM trade_offers WHERE status='accepted') AS accepted,
          (SELECT COUNT(*) FROM trade_offers WHERE status='pending') AS pending,
          (SELECT COUNT(*) FROM trades WHERE offer_id IS NOT NULL) AS linked_trades,
          (SELECT COUNT(*) FROM trades WHERE offer_id IS NOT NULL AND status='completed') AS linked_completed_trades,
          ROUND(100*(SELECT COUNT(DISTINCT offer_id) FROM trades WHERE offer_id IS NOT NULL)/NULLIF((SELECT COUNT(*) FROM trade_offers),0),1) AS offer_to_trade_pct,
          ROUND(100*(SELECT COUNT(DISTINCT offer_id) FROM trades WHERE offer_id IS NOT NULL AND status='completed')/NULLIF((SELECT COUNT(*) FROM trade_offers),0),1) AS offer_to_completed_pct,
          ROUND(AVG(TIMESTAMPDIFF(HOUR,o.created_at,t.created_at)),1) AS avg_offer_to_trade_hours
          FROM trade_offers o JOIN trades t ON t.offer_id=o.id"""),
        card('Offers by actual status', "SELECT status,COUNT(*) AS offers FROM trade_offers GROUP BY status ORDER BY offers DESC", 'bar'),
        card('Offers by type', "SELECT COALESCE(offer_type,'Unknown') AS offer_type,COUNT(*) AS offers FROM trade_offers GROUP BY offer_type ORDER BY offers DESC", 'bar'),
        card('Offers by flow', "SELECT COALESCE(flow_type,'Unknown') AS flow_type,COUNT(*) AS offers FROM trade_offers GROUP BY flow_type ORDER BY offers DESC", 'bar'),
        card('Offer trend', 'SELECT DATE(created_at) AS day,COUNT(*) AS offers FROM trade_offers GROUP BY DATE(created_at) ORDER BY day', 'line'),
        card('Linked offers and trades', "SELECT o.status AS offer_status,t.status AS trade_status,COUNT(*) AS trades,ROUND(AVG(TIMESTAMPDIFF(HOUR,o.created_at,t.created_at)),1) AS avg_offer_to_trade_hours,ROUND(AVG(CASE WHEN t.completed_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR,t.created_at,t.completed_at) END),1) AS avg_trade_to_complete_hours FROM trade_offers o JOIN trades t ON t.offer_id=o.id GROUP BY o.status,t.status"),
    ]),
    ('Swaapin — Trades', 'Status, payment and delivery values are grouped from real Production records.', [
        card('Trade KPIs', """SELECT COUNT(*) AS trades,
          SUM(status='completed') AS completed,
          SUM(status='in_progress') AS in_progress,
          SUM(status='disputed') AS disputed,
          SUM(status='cancelled') AS cancelled,
          ROUND(100*SUM(status='completed')/NULLIF(COUNT(*),0),1) AS completion_pct,
          ROUND(AVG(CASE WHEN completed_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR,created_at,completed_at) END),1) AS avg_completion_hours,
          SUM(credit_diff) AS credit_difference,
          SUM(escrow_amount) AS escrow_amount,
          SUM(bnpl_active=1) AS bnpl_trades,
          ROUND(AVG(CASE WHEN bnpl_active=1 THEN bnpl_months END),1) AS avg_bnpl_months,
          SUM(fee_paid) AS fee_paid_count,
          SUM(user_a_fee_paid) AS user_a_fee_paid_count,
          SUM(user_b_fee_paid) AS user_b_fee_paid_count,
          SUM(diff_paid) AS diff_paid_count,
          SUM(contract_signed_at IS NOT NULL) AS contracts_signed,
          SUM(user_a_delivered=1 AND user_b_delivered=1) AS both_delivered FROM trades"""),
        card('Trades by actual status', 'SELECT status,COUNT(*) AS trades FROM trades GROUP BY status ORDER BY trades DESC', 'bar'),
        card('Escrow states', "SELECT COALESCE(escrow_status,'Unknown') AS escrow_status,COUNT(*) AS trades,SUM(escrow_amount) AS amount FROM trades GROUP BY escrow_status ORDER BY trades DESC"),
        card('Selected payment methods', "SELECT COALESCE(selected_payment_method,'Unknown') AS method,COUNT(*) AS trades FROM trades GROUP BY selected_payment_method ORDER BY trades DESC", 'bar'),
        card('Selected shipping methods', "SELECT COALESCE(selected_shipping_method,'Unknown') AS method,COUNT(*) AS trades FROM trades GROUP BY selected_shipping_method ORDER BY trades DESC", 'bar'),
        card('Trade starts and completions by day', "SELECT DATE(created_at) AS day,COUNT(*) AS started,SUM(status='completed') AS now_completed FROM trades GROUP BY DATE(created_at) ORDER BY day", 'line'),
    ]),
    ('Swaapin — Payments & Revenue', 'Successful payment volume is gross throughput. Fees/refunds use actual wallet transaction types and escrow records.', [
        card('Payment and wallet KPIs', """SELECT (SELECT COUNT(*) FROM payments) AS payments,
          (SELECT COUNT(*) FROM payments WHERE status='success') AS successful_payments,
          (SELECT COUNT(*) FROM payments WHERE status='failed') AS failed_payments,
          (SELECT COUNT(*) FROM payments WHERE status='canceled') AS canceled_payments,
          (SELECT COUNT(*) FROM payments WHERE status='pending') AS pending_payments,
          ROUND(100*(SELECT COUNT(*) FROM payments WHERE status='success')/NULLIF((SELECT COUNT(*) FROM payments),0),1) AS success_pct,
          (SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='success') AS gross_successful_volume,
          (SELECT COALESCE(SUM(amount),0) FROM wallet_transactions WHERE `type`='deposit') AS wallet_deposits,
          (SELECT COALESCE(SUM(amount),0) FROM wallet_transactions WHERE `type`='withdraw') AS wallet_withdrawals,
          (SELECT COALESCE(SUM(amount),0) FROM wallet_transactions WHERE `type`='fee') AS wallet_fees,
          (SELECT COALESCE(SUM(amount),0) FROM wallet_transactions WHERE `type`='refund') AS wallet_refunds"""),
        card('Payments by status', 'SELECT status,COUNT(*) AS payments,SUM(amount) AS amount FROM payments GROUP BY status ORDER BY payments DESC'),
        card('Payments by business type', 'SELECT `type` AS payment_type,COUNT(*) AS payments,SUM(amount) AS amount FROM payments GROUP BY `type` ORDER BY payments DESC'),
        card('Gateway performance', "SELECT COALESCE(gateway,'Unknown') AS gateway,COUNT(*) AS payments,SUM(status='success') AS successful,ROUND(100*SUM(status='success')/NULLIF(COUNT(*),0),1) AS success_pct FROM payments GROUP BY gateway ORDER BY payments DESC"),
        card('Successful payment volume by day', "SELECT DATE(created_at) AS day,COUNT(*) AS payments,SUM(amount) AS amount FROM payments WHERE status='success' GROUP BY DATE(created_at) ORDER BY day", 'line'),
        card('Wallet transaction types', 'SELECT `type` AS wallet_type,COUNT(*) AS transactions,SUM(amount) AS amount FROM wallet_transactions GROUP BY `type` ORDER BY transactions DESC'),
        card('Escrow transaction types', 'SELECT `type` AS escrow_type,COUNT(*) AS transactions,SUM(amount) AS amount FROM escrow_transactions GROUP BY `type` ORDER BY transactions DESC'),
        card('Subscriptions, promotions and bumps', """SELECT 'subscription_orders' AS source,COUNT(*) AS transactions,COALESCE(SUM(amount_paid),0) AS amount FROM subscription_orders
          UNION ALL SELECT 'listing_promotions',COUNT(*),COALESCE(SUM(amount_paid),0) FROM listing_promotions
          UNION ALL SELECT 'listing_bumps',COUNT(*),COALESCE(SUM(amount_paid),0) FROM listing_bumps"""),
    ]),
    ('Swaapin — AI & Matching', 'Match shown/viewed/clicked stages are unavailable because no relevant events are recorded.', [
        card('AI and ISO matching KPIs', """SELECT (SELECT COUNT(*) FROM listing_ai_reviews) AS ai_reviews,
          (SELECT COUNT(DISTINCT listing_id) FROM listing_ai_reviews) AS reviewed_listings,
          (SELECT ROUND(AVG(ai_confidence),2) FROM listing_ai_reviews) AS avg_ai_confidence,
          (SELECT COUNT(*) FROM iso_matches) AS iso_matches,
          (SELECT ROUND(AVG(score),2) FROM iso_matches) AS avg_match_score,
          (SELECT ROUND(AVG(distance_km),2) FROM iso_matches) AS avg_match_distance_km"""),
        card('AI decisions', "SELECT COALESCE(ai_decision,'Unknown') AS decision,COUNT(*) AS reviews,ROUND(AVG(ai_confidence),2) AS avg_confidence FROM listing_ai_reviews GROUP BY ai_decision ORDER BY reviews DESC"),
        card('AI confidence distribution', "SELECT FLOOR(ai_confidence/10)*10 AS confidence_band,COUNT(*) AS reviews FROM listing_ai_reviews WHERE ai_confidence IS NOT NULL GROUP BY FLOOR(ai_confidence/10)*10 ORDER BY confidence_band", 'bar'),
        card('ISO match status', "SELECT COALESCE(status,'Unknown') AS status,COUNT(*) AS matches,ROUND(AVG(score),2) AS avg_score,ROUND(AVG(distance_km),2) AS avg_distance_km FROM iso_matches GROUP BY status ORDER BY matches DESC"),
        card('AI reviews by category', "SELECT c.name AS category,COUNT(*) AS reviews,ROUND(AVG(r.ai_confidence),2) AS avg_confidence FROM listing_ai_reviews r JOIN listings l ON l.id=r.listing_id LEFT JOIN categories c ON c.id=l.category_id GROUP BY c.id,c.name ORDER BY reviews DESC LIMIT 20"),
        card('AI reviews by day', 'SELECT DATE(created_at) AS day,COUNT(*) AS reviews FROM listing_ai_reviews GROUP BY DATE(created_at) ORDER BY day', 'line'),
    ]),
    ('Swaapin — Engagement', 'User listing chats, offer threads, support live chat and trade secure rooms are distinct subsystems.', [
        card('Engagement totals by subsystem', """SELECT 'saved_listings' AS subsystem,COUNT(*) AS records FROM saved_listings
          UNION ALL SELECT 'saved_searches',COUNT(*) FROM saved_searches
          UNION ALL SELECT 'saved_search_hits',COUNT(*) FROM saved_search_hits
          UNION ALL SELECT 'listing_conversations',COUNT(*) FROM user_conversations
          UNION ALL SELECT 'listing_messages',COUNT(*) FROM user_messages
          UNION ALL SELECT 'offer_thread_messages',COUNT(*) FROM messages
          UNION ALL SELECT 'support_live_chats',COUNT(*) FROM live_chats
          UNION ALL SELECT 'support_live_chat_messages',COUNT(*) FROM live_chat_messages
          UNION ALL SELECT 'trade_secure_room_messages',COUNT(*) FROM secure_room_messages"""),
        card('Saved listings not in a trade', "SELECT COUNT(*) AS saved_listings_without_trade FROM saved_listings s WHERE NOT EXISTS (SELECT 1 FROM trades t WHERE t.listing_a_id=s.listing_id OR t.listing_b_id=s.listing_id)"),
        card('Listing conversations and messages', 'SELECT COUNT(*) AS conversations,COUNT(DISTINCT c.listing_id) AS listings_with_conversations,COUNT(m.id) AS messages,ROUND(COUNT(m.id)/NULLIF(COUNT(DISTINCT c.id),0),2) AS messages_per_conversation FROM user_conversations c LEFT JOIN user_messages m ON m.conversation_id=c.id'),
        card('Listings without conversations', 'SELECT COUNT(*) AS listings_without_conversations FROM listings l WHERE NOT EXISTS (SELECT 1 FROM user_conversations c WHERE c.listing_id=l.id)'),
        card('Conversations linked to offers', 'SELECT COUNT(DISTINCT c.id) AS conversations_with_listing_offer FROM user_conversations c JOIN trade_offers o ON o.listing_id=c.listing_id'),
        card('Conversations linked to trades', 'SELECT COUNT(DISTINCT c.id) AS conversations_with_listing_trade FROM user_conversations c JOIN trades t ON t.listing_a_id=c.listing_id OR t.listing_b_id=c.listing_id'),
    ]),
    ('Swaapin — Categories & Geography', 'Category hierarchy uses parent_id. Offer and trade category attribution follows the primary offered listing.', [
        card('Category performance', """SELECT c.name AS category,p.name AS parent_category,
          (SELECT COUNT(*) FROM listings l WHERE l.category_id=c.id) AS listings,
          (SELECT COALESCE(SUM(l.views),0) FROM listings l WHERE l.category_id=c.id) AS views,
          (SELECT COUNT(*) FROM trade_offers o JOIN listings l ON l.id=o.listing_id WHERE l.category_id=c.id) AS offers,
          (SELECT COUNT(*) FROM trades t JOIN trade_offers o ON o.id=t.offer_id JOIN listings l ON l.id=o.listing_id WHERE l.category_id=c.id AND t.status='completed') AS completed_trades,
          ROUND(100*(SELECT COUNT(*) FROM trades t JOIN trade_offers o ON o.id=t.offer_id JOIN listings l ON l.id=o.listing_id WHERE l.category_id=c.id AND t.status='completed')/
          NULLIF((SELECT COUNT(*) FROM trade_offers o JOIN listings l ON l.id=o.listing_id WHERE l.category_id=c.id),0),1) AS offer_to_completed_pct
          FROM categories c LEFT JOIN categories p ON p.id=c.parent_id ORDER BY listings DESC LIMIT 50"""),
        card('Category hierarchy', 'SELECT c.id,c.name AS category,p.name AS parent_category,c.sort_order,c.is_active FROM categories c LEFT JOIN categories p ON p.id=c.parent_id ORDER BY p.name,c.sort_order,c.name'),
        card('Users by city', "SELECT COALESCE(NULLIF(city,''),'Unknown') AS city,COUNT(*) AS users FROM users GROUP BY city ORDER BY users DESC LIMIT 30", 'bar'),
        card('Listings by city', "SELECT COALESCE(NULLIF(city,''),'Unknown') AS city,COUNT(*) AS listings,SUM(views) AS views FROM listings GROUP BY city ORDER BY listings DESC LIMIT 30", 'bar'),
        card('Completed trades by listing city', "SELECT COALESCE(NULLIF(l.city,''),'Unknown') AS city,COUNT(DISTINCT t.id) AS completed_trades FROM trades t LEFT JOIN listings l ON l.id=t.listing_a_id WHERE t.status='completed' GROUP BY l.city ORDER BY completed_trades DESC LIMIT 30", 'bar'),
    ]),
    ('Swaapin — Trust / Safety / Operations', 'Operational status and resolution timing use actual table timestamps.', [
        card('Trust and operations totals', """SELECT (SELECT COUNT(*) FROM disputes) AS disputes,
          (SELECT COUNT(*) FROM inspection_requests) AS inspection_requests,
          (SELECT COUNT(*) FROM support_tickets) AS support_tickets,
          (SELECT COUNT(*) FROM reviews) AS reviews,
          (SELECT COUNT(*) FROM error_reports) AS error_reports,
          (SELECT ROUND(AVG(TIMESTAMPDIFF(HOUR,created_at,resolved_at)),1) FROM disputes WHERE resolved_at IS NOT NULL) AS avg_dispute_resolution_hours,
          (SELECT ROUND(AVG(TIMESTAMPDIFF(HOUR,created_at,closed_at)),1) FROM support_tickets WHERE closed_at IS NOT NULL) AS avg_support_close_hours,
          (SELECT ROUND(AVG(TIMESTAMPDIFF(HOUR,created_at,resolved_at)),1) FROM error_reports WHERE resolved_at IS NOT NULL) AS avg_error_resolution_hours"""),
        card('Dispute statuses', 'SELECT status,COUNT(*) AS disputes FROM disputes GROUP BY status ORDER BY disputes DESC'),
        card('Inspection statuses', 'SELECT status,COUNT(*) AS requests FROM inspection_requests GROUP BY status ORDER BY requests DESC'),
        card('Support ticket status and priority', 'SELECT status,priority,COUNT(*) AS tickets FROM support_tickets GROUP BY status,priority ORDER BY tickets DESC'),
        card('Error report statuses', 'SELECT status,COUNT(*) AS reports FROM error_reports GROUP BY status ORDER BY reports DESC'),
        card('Review ratings', 'SELECT rating,COUNT(*) AS reviews FROM reviews GROUP BY rating ORDER BY rating', 'bar'),
    ]),
]

collection_id = state.get('collection_id')
if not collection_id:
    collection_id = api.post('/api/collection', {'name': 'Swaapin Analytics', 'description': 'Aggregate production reporting. Source: swapin read-only.'})['id']
    state['collection_id'] = collection_id
    save()

failed = []
refresh_existing = {
    'Swaapin — Executive Overview / Operational funnel — distinct people',
    'Swaapin — Categories & Geography / Category performance',
}
for dashboard_name, description, cards in dashboards:
    dashboard_id = state['dashboards'].get(dashboard_name)
    if not dashboard_id:
        dashboard_id = api.post('/api/dashboard', {'name': dashboard_name, 'description': description, 'collection_id': collection_id})['id']
        state['dashboards'][dashboard_name] = dashboard_id
        save()
    dashcards = []
    for position, item in enumerate(cards):
        key = dashboard_name + ' / ' + item['name']
        card_id = state['cards'].get(key)
        dataset_query = {'database': 2, 'type': 'native', 'native': {'query': item['sql']}}
        if card_id and key in refresh_existing:
            result = api.post('/api/dataset', dataset_query)
            if result.get('status') == 'failed' or result.get('error'):
                failed.append(key + ': updated query failed')
            else:
                api.put(f'/api/card/{card_id}', {'dataset_query': dataset_query, 'description': item.get('description')})
        if not card_id:
            try:
                result = api.post('/api/dataset', dataset_query)
                if result.get('status') == 'failed' or result.get('error'):
                    raise RuntimeError('query failed')
                payload = {'name': item['name'], 'description': item.get('description'), 'collection_id': collection_id,
                           'dataset_query': dataset_query, 'display': item['display'], 'visualization_settings': {}}
                card_id = api.post('/api/card', payload)['id']
                state['cards'][key] = card_id
                save()
            except RuntimeError as exc:
                failed.append(key + ': ' + str(exc))
                continue
        dashcards.append({'id': -(position+1), 'card_id': card_id, 'row': (position//2)*4,
                          'col': (position%2)*12, 'size_x': 12, 'size_y': 4})
    if dashcards:
        try:
            api.put(f'/api/dashboard/{dashboard_id}/cards', {'cards': dashcards})
        except RuntimeError as exc:
            failed.append(dashboard_name + ' layout: ' + str(exc))
    print(f'DASHBOARD={dashboard_name} cards={len(dashcards)}/{len(cards)}')

for item in failed:
    print('FAILED=' + item)
print(f'DASHBOARDS={len(state["dashboards"])} CARDS={len(state["cards"])} FAILURES={len(failed)}')
if failed:
    raise SystemExit(1)
