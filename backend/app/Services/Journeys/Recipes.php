<?php

namespace App\Services\Journeys;

/**
 * Ready-made journeys. Each uses only triggers that work today; email steps come with a subject and
 * preview text, and the customer adds the design and sender before turning it on.
 */
class Recipes
{
    /** @return list<array{key: string, name: string, trigger: string, steps: int, about: string}> */
    public static function catalog(): array
    {
        return array_map(fn ($k) => ['key' => $k, 'name' => self::get($k)['name'], 'trigger' => self::get($k)['trigger'], 'steps' => count(self::get($k)['nodes']) - 1, 'about' => self::get($k)['about']], self::KEYS);
    }

    public const KEYS = ['welcome', 'abandoned_checkout', 'birthday', 'trial_ending', 'subscription_expiring', 'post_purchase', 'payment_failed', 'win_back', 'browse_abandoned', 'price_drop', 'back_in_stock', 'shopify_abandoned_checkout', 'stripe_payment_failed'];

    /** @return array{name: string, trigger: string, about: string, reentry: string, nodes: list<array<string, mixed>>, edges: list<array<string, string>>, emails: array<string, array{subject: string, preheader?: string}>}|null */
    public static function get(string $key): ?array
    {
        $n = fn (string $id, string $type, int $col, int $row, string $title, array $config = []) => ['id' => $id, 'type' => $type, 'x' => $col * 320, 'y' => 120 + $row * 170, 'title' => $title, 'config' => $config];
        $e = fn (string $from, string $to, string $branch = '') => ['from' => $from, 'to' => $to, 'branch' => $branch];
        $mk = fn (string $id, int $col, int $row, string $title) => $n($id, 'email', $col, $row, $title, ['type' => 'marketing', 'skip_recent_hours' => 0]);

        return match ($key) {
            'welcome' => ['name' => 'Welcome series', 'trigger' => 'Contact created', 'reentry' => 'once', 'about' => 'Welcome new contacts, then follow up based on whether they opened.',
                'nodes' => [
                    $n('t1', 'trigger', 0, 0, 'Contact created', ['trigger' => 'contact_created']),
                    $mk('e1', 1, 0, 'Welcome email'),
                    $n('w1', 'wait', 2, 0, 'Wait 2 days', ['amount' => 2, 'unit' => 'days']),
                    $n('c1', 'cond', 3, 0, 'Opened the welcome?', ['rules' => ['match' => 'all', 'conditions' => [['field' => 'journey_email', 'op' => 'opened', 'value' => 'e1']]]]),
                    $mk('e2', 4, -1, 'Getting started tips'),
                    $mk('e3', 4, 1, 'In case you missed it'),
                ],
                'edges' => [$e('t1', 'e1'), $e('e1', 'w1'), $e('w1', 'c1'), $e('c1', 'e2', 'yes'), $e('c1', 'e3', 'no')],
                'emails' => ['e1' => ['subject' => 'Welcome, {{ first_name | there }}', 'preheader' => 'Here’s what happens next'], 'e2' => ['subject' => 'Three tips to get started'],
                    'e3' => ['subject' => 'Did you see this?', 'preheader' => 'A quick hello, in case it got buried']]],
            'abandoned_checkout' => ['name' => 'Abandoned checkout', 'trigger' => 'Event: checkout_started', 'reentry' => 'always', 'about' => 'Remind people who started a checkout but didn’t order.',
                'nodes' => [
                    $n('t1', 'trigger', 0, 0, 'Checkout started', ['trigger' => 'event', 'event' => 'checkout_started']),
                    $n('f1', 'waitfor', 1, 0, 'Ordered within an hour?', ['event' => 'order_placed', 'amount' => 1, 'unit' => 'hours']),
                    $mk('e1', 2, 1, 'You left something behind'),
                    $n('w1', 'wait', 3, 1, 'Wait 1 day', ['amount' => 1, 'unit' => 'days']),
                    $mk('e2', 4, 1, 'Still thinking it over?'),
                    $n('g1', 'goal', 2, -1, 'Order placed', ['event' => 'order_placed']),
                ],
                'edges' => [$e('t1', 'f1'), $e('f1', 'g1', 'yes'), $e('f1', 'e1', 'no'), $e('e1', 'w1'), $e('w1', 'e2')],
                'emails' => ['e1' => ['subject' => 'You left something in your cart'], 'e2' => ['subject' => 'Still thinking it over?']]],
            'birthday' => ['name' => 'Birthday', 'trigger' => 'Date: birthday', 'reentry' => 'always', 'about' => 'A birthday email at 9:00 in each contact’s time zone, every year.',
                'nodes' => [$n('t1', 'trigger', 0, 0, 'Birthday', ['trigger' => 'date', 'field' => 'birthday', 'offset_days' => 0, 'hour' => 9, 'yearly' => true]), $mk('e1', 1, 0, 'Happy birthday')],
                'edges' => [$e('t1', 'e1')], 'emails' => ['e1' => ['subject' => 'Happy birthday, {{ first_name | friend }}!']]],
            'trial_ending' => ['name' => 'Trial ending', 'trigger' => 'Date: 3 days before trial_ends_at', 'reentry' => 'once', 'about' => 'Nudge trial users before their trial ends, stop when they upgrade.',
                'nodes' => [
                    $n('t1', 'trigger', 0, 0, 'Trial ends in 3 days', ['trigger' => 'date', 'field' => 'trial_ends_at', 'offset_days' => -3, 'hour' => 10, 'yearly' => false]),
                    $mk('e1', 1, 0, 'Your trial ends soon'),
                    $n('f1', 'waitfor', 2, 0, 'Upgraded within 2 days?', ['event' => 'plan_upgraded', 'amount' => 2, 'unit' => 'days']),
                    $n('g1', 'goal', 3, -1, 'Upgraded', ['event' => 'plan_upgraded']),
                    $mk('e2', 3, 1, 'Last day of your trial'),
                ],
                'edges' => [$e('t1', 'e1'), $e('e1', 'f1'), $e('f1', 'g1', 'yes'), $e('f1', 'e2', 'no')],
                'emails' => ['e1' => ['subject' => 'Your trial ends in 3 days'], 'e2' => ['subject' => 'Your trial ends tomorrow']]],
            'subscription_expiring' => ['name' => 'Fan subscription expiring', 'trigger' => 'Date: 3 days before subscription_ends_at', 'reentry' => 'always', 'about' => 'Remind fans to renew before their subscription ends.',
                'nodes' => [
                    $n('t1', 'trigger', 0, 0, 'Subscription ends in 3 days', ['trigger' => 'date', 'field' => 'subscription_ends_at', 'offset_days' => -3, 'hour' => 18, 'yearly' => false]),
                    $mk('e1', 1, 0, 'Renew reminder'),
                    $n('f1', 'waitfor', 2, 0, 'Renewed within 2 days?', ['event' => 'subscription_renewed', 'amount' => 2, 'unit' => 'days']),
                    $n('g1', 'goal', 3, -1, 'Renewed', ['event' => 'subscription_renewed']),
                    $mk('e2', 3, 1, 'Last chance to renew'),
                ],
                'edges' => [$e('t1', 'e1'), $e('e1', 'f1'), $e('f1', 'g1', 'yes'), $e('f1', 'e2', 'no')],
                'emails' => ['e1' => ['subject' => 'Your subscription ends in 3 days'], 'e2' => ['subject' => 'Last chance to keep your subscription']]],
            'post_purchase' => ['name' => 'Post-purchase review', 'trigger' => 'Event: order_placed', 'reentry' => 'always', 'about' => 'Ask for a review a week after each order.',
                'nodes' => [$n('t1', 'trigger', 0, 0, 'Order placed', ['trigger' => 'event', 'event' => 'order_placed']), $n('w1', 'wait', 1, 0, 'Wait 7 days', ['amount' => 7, 'unit' => 'days']),
                    $n('s1', 'window', 2, 0, 'Weekdays, 9:00–18:00', ['days' => [1, 2, 3, 4, 5], 'from' => '09:00', 'to' => '18:00']), $mk('e1', 3, 0, 'How was your order?')],
                'edges' => [$e('t1', 'w1'), $e('w1', 's1'), $e('s1', 'e1')], 'emails' => ['e1' => ['subject' => 'How was your order, {{ first_name | there }}?']]],
            'payment_failed' => ['name' => 'Payment failed', 'trigger' => 'Event: payment_failed', 'reentry' => 'always', 'about' => 'Help customers fix a failed payment, stop once it succeeds.',
                'nodes' => [
                    $n('t1', 'trigger', 0, 0, 'Payment failed', ['trigger' => 'event', 'event' => 'payment_failed']),
                    $n('e1', 'email', 1, 0, 'Payment didn’t go through', ['type' => 'transactional', 'skip_recent_hours' => 0]),
                    $n('f1', 'waitfor', 2, 0, 'Paid within 2 days?', ['event' => 'payment_succeeded', 'amount' => 2, 'unit' => 'days']),
                    $n('g1', 'goal', 3, -1, 'Payment succeeded', ['event' => 'payment_succeeded']),
                    $n('e2', 'email', 3, 1, 'Action needed', ['type' => 'transactional', 'skip_recent_hours' => 0]),
                ],
                'edges' => [$e('t1', 'e1'), $e('e1', 'f1'), $e('f1', 'g1', 'yes'), $e('f1', 'e2', 'no')],
                'emails' => ['e1' => ['subject' => 'Your payment didn’t go through'], 'e2' => ['subject' => 'Action needed: update your payment details']]],
            'win_back' => ['name' => 'Win-back', 'trigger' => 'Went quiet: 60 days', 'reentry' => 'always', 'about' => 'Reach people who stopped opening; tag the ones who still don’t, so you can stop mailing them.',
                'nodes' => [
                    $n('t1', 'trigger', 0, 0, 'Went quiet for 60 days', ['trigger' => 'went_quiet', 'days' => 60]),
                    $mk('e1', 1, 0, 'We miss you'),
                    $n('f1', 'waitfor', 2, 0, 'Opened within 7 days?', ['event' => 'email.opened', 'node' => 'e1', 'amount' => 7, 'unit' => 'days']),
                    $n('x1', 'exit', 3, -1, 'Came back'),
                    $mk('e2', 3, 1, 'Should we stop emailing?'),
                    $n('f2', 'waitfor', 4, 1, 'Opened within 7 days?', ['event' => 'email.opened', 'node' => 'e2', 'amount' => 7, 'unit' => 'days']),
                    $n('k1', 'tag', 5, 2, 'Tag: unengaged', ['action' => 'add', 'tag' => 'unengaged']),
                ],
                'edges' => [$e('t1', 'e1'), $e('e1', 'f1'), $e('f1', 'x1', 'yes'), $e('f1', 'e2', 'no'), $e('e2', 'f2'), $e('f2', 'k1', 'no')],
                'emails' => ['e1' => ['subject' => 'We miss you, {{ first_name | there }}', 'preheader' => 'Here’s what you’ve missed'],
                    'e2' => ['subject' => 'Should we stop emailing you?', 'preheader' => 'One click keeps you on the list']]],
            'browse_abandoned' => ['name' => 'Browse abandoned', 'trigger' => 'Event: product_viewed', 'reentry' => 'cooldown', 'about' => 'Someone looked at a product but didn’t add it to the cart: show it to them again.',
                'nodes' => [
                    $n('t1', 'trigger', 0, 0, 'Viewed a product', ['trigger' => 'event', 'event' => 'product_viewed']),
                    $n('f1', 'waitfor', 1, 0, 'Added to cart within 2 hours?', ['event' => 'added_to_cart', 'amount' => 2, 'unit' => 'hours']),
                    $n('x1', 'exit', 2, -1, 'Added to cart'),
                    $mk('e1', 2, 1, 'Still thinking about it?'),
                ],
                'edges' => [$e('t1', 'f1'), $e('f1', 'x1', 'yes'), $e('f1', 'e1', 'no')],
                'emails' => ['e1' => ['subject' => 'Still thinking about {{ name | it }}?', 'preheader' => 'It’s waiting for you']]],
            'price_drop' => ['name' => 'Price drop', 'trigger' => 'Price drop', 'reentry' => 'always', 'about' => 'Tell people who viewed a product when its price goes down (from POST /v1/products or Shopify).',
                'nodes' => [$n('t1', 'trigger', 0, 0, 'Price dropped', ['trigger' => 'price_drop', 'min_percent' => 5]),
                    $n('s1', 'window', 1, 0, 'Daytime', ['days' => [1, 2, 3, 4, 5, 6, 7], 'from' => '09:00', 'to' => '20:00']), $mk('e1', 2, 0, 'Price drop')],
                'edges' => [$e('t1', 's1'), $e('s1', 'e1')], 'emails' => ['e1' => ['subject' => '{{ name | Something you viewed }} is now {{ price }}', 'preheader' => 'Down from {{ old_price }}']]],
            'back_in_stock' => ['name' => 'Back in stock', 'trigger' => 'Back in stock', 'reentry' => 'always', 'about' => 'Tell people who asked (or looked) when a product is available again.',
                'nodes' => [$n('t1', 'trigger', 0, 0, 'Back in stock', ['trigger' => 'back_in_stock']), $mk('e1', 1, 0, 'It’s back')],
                'edges' => [$e('t1', 'e1')], 'emails' => ['e1' => ['subject' => '{{ name | It }} is back in stock', 'preheader' => 'Get it before it’s gone again']]],
            'shopify_abandoned_checkout' => ['name' => 'Shopify: abandoned checkout', 'trigger' => 'Event: shopify.checkouts.create', 'reentry' => 'cooldown', 'about' => 'With the Shopify integration: remind people who started checking out but didn’t order, with a link back to their cart.',
                'nodes' => [
                    $n('t1', 'trigger', 0, 0, 'Checkout started', ['trigger' => 'event', 'event' => 'shopify.checkouts.create']),
                    $n('f1', 'waitfor', 1, 0, 'Ordered within an hour?', ['event' => 'shopify.orders.create', 'amount' => 1, 'unit' => 'hours']),
                    $n('g1', 'goal', 2, -1, 'Ordered', ['event' => 'shopify.orders.create']),
                    $mk('e1', 2, 1, 'Your cart is waiting'),
                    $n('w1', 'wait', 3, 1, 'Wait 1 day', ['amount' => 1, 'unit' => 'days']),
                    $mk('e2', 4, 1, 'Last reminder'),
                ],
                'edges' => [$e('t1', 'f1'), $e('f1', 'g1', 'yes'), $e('f1', 'e1', 'no'), $e('e1', 'w1'), $e('w1', 'e2')],
                'emails' => ['e1' => ['subject' => 'You left something in your cart', 'preheader' => 'Finish your order in one click'], 'e2' => ['subject' => 'Your cart is about to expire']]],
            'stripe_payment_failed' => ['name' => 'Stripe: payment failed', 'trigger' => 'Event: stripe.invoice.payment_failed', 'reentry' => 'always', 'about' => 'With the Stripe integration: help customers fix a failed payment, with the invoice link; stops once it’s paid.',
                'nodes' => [
                    $n('t1', 'trigger', 0, 0, 'Invoice payment failed', ['trigger' => 'event', 'event' => 'stripe.invoice.payment_failed']),
                    $n('e1', 'email', 1, 0, 'Payment didn’t go through', ['type' => 'transactional', 'skip_recent_hours' => 0]),
                    $n('f1', 'waitfor', 2, 0, 'Paid within 3 days?', ['event' => 'stripe.invoice.paid', 'amount' => 3, 'unit' => 'days']),
                    $n('g1', 'goal', 3, -1, 'Invoice paid', ['event' => 'stripe.invoice.paid']),
                    $n('e2', 'email', 3, 1, 'Action needed', ['type' => 'transactional', 'skip_recent_hours' => 0]),
                ],
                'edges' => [$e('t1', 'e1'), $e('e1', 'f1'), $e('f1', 'g1', 'yes'), $e('f1', 'e2', 'no')],
                'emails' => ['e1' => ['subject' => 'Your payment didn’t go through', 'preheader' => 'Update your card: {{ invoice_url }}'], 'e2' => ['subject' => 'Action needed: your subscription payment']]],
            default => null,
        };
    }
}
