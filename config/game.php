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
    | Quarterly contribution (₽) the player allocates each move (DCA model).
    | 12 equal contributions, no starting capital.
    */
    'contribution' => (int) env('GAME_CONTRIBUTION', 30000),

    /*
    | Legacy single starting capital — no longer used under the DCA model.
    | Kept at 0 so any stale reference cannot reintroduce a lump sum.
    */
    'start_amount' => 0,
];
