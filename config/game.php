<?php

return [
    /*
    | Promo code shown to every player who finishes the game.
    | This is a real, working code already configured on the Финуслуги storefront.
    */
    'promo_code' => env('GAME_PROMO_CODE', 'GAME1'),

    /*
    | Storefront the "use promo code" button links to.
    */
    'shop_url' => env('GAME_SHOP_URL', 'https://finuslugi.ru/invest/funds'),

    /*
    | Starting portfolio for every player (₽).
    */
    'start_amount' => 300000,
];
