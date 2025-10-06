<?php

$orders = wc_get_orders([
    'status' => ['completed', 'processing', 'on-hold'],
    'limit'  => 20,
    'date_created' => '>=2025-09-01',
]);

?>

<style>
    .swiper {
        width: 100%;
        height: 100%;
    }

    .swiper-slide {
        text-align: center;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        height: calc((100% - 30px) / 2) !important;
        gap: 8px;
        border-radius: 8px;
        padding: 16px;
        color: white;
    }

    .gl-donor-card--tier-1 {
        background-color: var(--theme-palette-color-4);
    }

    .gl-donor-card--tier-2 {
        background-color: var(--theme-palette-color-3);
    }

    .gl-donor-card--tier-3 {
        background-color: var(--theme-palette-color-2);
    }

    .gl-donor-card--tier-4 {
        background-color: var(--theme-palette-color-1);
    }
</style>

<div class="swiper gl-donor-card__swiper">
    <div class="swiper-wrapper">
    <?php foreach ($orders as $order) :
        $total = $order->get_total();
        if ($total < 10) {
            $color_class = "gl-donor-card--tier-1";
        } else if ($total < 250) {
            $color_class = "gl-donor-card--tier-2";
        } else if ($total < 500) {
            $color_class = "gl-donor-card--tier-3";
        } else {
            $color_class = "gl-donor-card--tier-4";
        }
    ?>
        <div class="swiper-slide <?= $color_class; ?>">
            <span class="gl-donor-card__total">$<?= esc_html(number_format($total, 2)); ?> - <?= esc_html(substr($order->get_billing_first_name(), 0, 1)); ?>.<?= esc_html(substr($order->get_billing_last_name(), 0, 1)); ?>.</span>
            <div class="gl-donor-card__city"><?= esc_html($order->get_billing_city()); ?></div>
            <time class="gl-donor-card__timeago" datetime="<?= esc_attr($order->get_date_created()->date('c')); ?>"><?= esc_html($order->get_date_created()->date_i18n(get_option('date_format'))); ?></time>
        </div>
    <?php endforeach; ?>
    </div>
    <div class="swiper-button-next"></div>
    <div class="swiper-button-prev"></div>
</div>


<script src="https://cdn.jsdelivr.net/npm/swiper@12/swiper-bundle.min.js"></script>
<script>
    // Defaults
    const defaultPostsPerSlide = 10;

    // Presets
    const presets = {
        2: {
            mobile: {
                slidesPerRow: 1,
                rows: 1,
            },
            tablet: {
                slidesPerRow: 2,
                rows: 1,
            },
            desktop: {
                slidesPerRow: 2,
                rows: 1,
            },
        },
        10: {
            mobile: {
                slidesPerRow: 1,
                rows: 1,
            },
            tablet: {
                slidesPerRow: 2,
                rows: 1,
            },
            desktop: {
                slidesPerRow: 5,
                rows: 2,
            },
        },
    };
    const preset = presets[defaultPostsPerSlide];
    console.log(preset);
    const swiper = new Swiper(".gl-donor-card__swiper", {
        slidesPerGroup: preset.mobile.slidesPerRow,
        slidesPerView: preset.mobile.slidesPerRow,
        grid: {
            fill: "row",
            rows: preset.mobile.rows,
        },
        loop: true,
        spaceBetween: 20,
        navigation: {
            nextEl: ".swiper-button-next",
            prevEl: ".swiper-button-prev",
        },
        breakpoints: {
            768: {
                slidesPerGroup: preset.tablet.slidesPerRow,
                slidesPerView: preset.tablet.slidesPerRow,
                spaceBetween: 32, // Matches $site-padding.tablet
                grid: {
                    rows: preset.tablet.rows,
                },
                pagination: {
                    enabled: true,
                },
            },
            1024: {
                slidesPerGroup: preset.desktop.slidesPerRow,
                slidesPerView: preset.desktop.slidesPerRow,
                spaceBetween: 32, // Matches $site-padding.desktop
                grid: {
                    rows: preset.desktop.rows,
                },
                pagination: {
                    enabled: true,
                },
            },
        },

    });
</script>