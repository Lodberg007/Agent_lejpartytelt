/**
 * LPT Prisberegner — calculator.js
 * Lejpartytelt.dk — alle priser inkl. moms, pr. dag
 */
(function ($) {
    'use strict';

    /* ────────────────────────────────────────────
       PRISDATA
    ──────────────────────────────────────────── */
    const MULTIPLIERS = { 1: 1.0, 2: 1.4, 3: 1.7 };

    // Kapacitet er et estimat: antal siddende gæster ved borde
    const TENTS = {
        '3': [
            { id: 't3x3',  label: '3 × 3 m, hvid',  price: 1200,  capacity: 10,  sqm: 9,   color: 'hvid' },
            { id: 't3x6',  label: '3 × 6 m, hvid',  price: 1700,  capacity: 20,  sqm: 18,  color: 'hvid' },
            { id: 't3x9',  label: '3 × 9 m, hvid',  price: 2000,  capacity: 28,  sqm: 27,  color: 'hvid' },
            { id: 't3x12', label: '3 × 12 m, hvid', price: 2700,  capacity: 36,  sqm: 36,  color: 'hvid' },
        ],
        '6': [
            { id: 't6x3',  label: '6 × 3 m, hvid',  price: 1700,  capacity: 15,  sqm: 18,  color: 'hvid' },
            { id: 't6x6',  label: '6 × 6 m, hvid',  price: 2000,  capacity: 28,  sqm: 36,  color: 'hvid' },
            { id: 't6x9',  label: '6 × 9 m, hvid',  price: 2400,  capacity: 48,  sqm: 54,  color: 'hvid' },
            { id: 't6x12', label: '6 × 12 m, hvid', price: 3100,  capacity: 64,  sqm: 72,  color: 'hvid' },
            { id: 't6x15', label: '6 × 15 m, hvid', price: 3800,  capacity: 80,  sqm: 90,  color: 'hvid' },
            { id: 't6x18', label: '6 × 18 m, hvid', price: 4500,  capacity: 96,  sqm: 108, color: 'hvid' },
            { id: 't6x21', label: '6 × 21 m, hvid', price: 5200,  capacity: 112, sqm: 126, color: 'hvid' },
            { id: 't6x24', label: '6 × 24 m, hvid', price: 5900,  capacity: 128, sqm: 144, color: 'hvid' },
            { id: 't6x27', label: '6 × 27 m, hvid', price: 6600,  capacity: 144, sqm: 162, color: 'hvid' },
        ],
        '9': [
            { id: 't9x3',  label: '9 × 3 m, hvid',   price: 2000,  capacity: 20,  sqm: 27,  color: 'hvid' },
            { id: 't9x6',  label: '9 × 6 m, hvid',   price: 2400,  capacity: 40,  sqm: 54,  color: 'hvid' },
            { id: 't9x9',  label: '9 × 9 m, hvid',   price: 3500,  capacity: 60,  sqm: 81,  color: 'hvid' },
            { id: 't9x12', label: '9 × 12 m, hvid',  price: 4600,  capacity: 80,  sqm: 108, color: 'hvid' },
            { id: 't9x15', label: '9 × 15 m, hvid',  price: 5600,  capacity: 100, sqm: 135, color: 'hvid' },
            { id: 't9x18', label: '9 × 18 m, hvid',  price: 6600,  capacity: 120, sqm: 162, color: 'hvid' },
            { id: 't9x21', label: '9 × 21 m, hvid',  price: 7600,  capacity: 140, sqm: 189, color: 'hvid' },
            { id: 't9x24', label: '9 × 24 m, hvid',  price: 8600,  capacity: 160, sqm: 216, color: 'hvid' },
            { id: 't9x27', label: '9 × 27 m, hvid',  price: 9600,  capacity: 180, sqm: 243, color: 'hvid' },
            { id: 't9x30', label: '9 × 30 m, hvid',  price: 10600, capacity: 200, sqm: 270, color: 'hvid' },
            { id: 't9x33', label: '9 × 33 m, hvid',  price: 11600, capacity: 220, sqm: 297, color: 'hvid' },
            { id: 't9x36', label: '9 × 36 m, hvid',  price: 12600, capacity: 240, sqm: 324, color: 'hvid' },
            { id: 't9x9s', label: '9 × 9 m, SORT (scenetelt)', price: 3700, capacity: 60, sqm: 81, color: 'sort' },
            { id: 't9x6s', label: '9 × 6 m, SORT (scenetelt)', price: 3100, capacity: 40, sqm: 54, color: 'sort' },
            { id: 't9x3s', label: '9 × 3 m, SORT (scenetelt)', price: 2500, capacity: 20, sqm: 27, color: 'sort' },
        ],
        'pavillon': [
            { id: 'pav3x6s', label: 'Pavillon 3 × 6 m, sort',      price: 1200, capacity: 18, sqm: 18,  color: 'sort' },
            { id: 'pav4x8h', label: 'Pavillon 4 × 8 m, hvid',      price: 1440, capacity: 24, sqm: 32,  color: 'hvid' },
            { id: 'pav6kant', label: 'Pavillon 6-kantet, sort',     price: 1380, capacity: 20, sqm: 28,  color: 'sort' },
        ],
    };

    const ACCESSORIES = [
        {
            group: 'Stole',
            items: [
                { id: 'stol_hvid',   label: 'Stol, hvid plastik',           price: 7.20,  unit: 'stk.',  hasQty: true,  defaultQty: 0 },
                { id: 'stol_hynde',  label: 'Stol, hvid m. sædehynde',      price: 10.40, unit: 'stk.',  hasQty: true,  defaultQty: 0 },
                { id: 'stol_sort',   label: 'Stol, polstret sort',           price: 12.80, unit: 'stk.',  hasQty: true,  defaultQty: 0 },
            ],
        },
        {
            group: 'Borde',
            items: [
                { id: 'bord_rect',   label: 'Bord, 75×180 cm',              price: 36,    unit: 'stk.',  hasQty: true,  defaultQty: 0 },
                { id: 'staabord',    label: 'Ståbord',                       price: 56,    unit: 'stk.',  hasQty: true,  defaultQty: 0 },
                { id: 'bord_rund',   label: 'Rundt bord, Ø160 cm',          price: 72,    unit: 'stk.',  hasQty: true,  defaultQty: 0 },
                { id: 'baenksaet',   label: 'Bord/bænkesæt, træ',           price: 120,   unit: 'stk.',  hasQty: true,  defaultQty: 0 },
            ],
        },
        {
            group: 'Gulv',
            items: [
                { id: 'gulv_lev',    label: 'Gulv, grå (leveret/afhentet)', price: 24,    unit: 'm²',    hasQty: true,  defaultQty: 0 },
                { id: 'gulv_afhent', label: 'Gulv, grå (kunden afhenter)',  price: 20,    unit: 'm²',    hasQty: true,  defaultQty: 0 },
            ],
        },
        {
            group: 'Varme',
            items: [
                { id: 'varme15',     label: 'Varmekanon, 15 kw gas',        price: 140,   unit: 'stk.',  hasQty: true,  defaultQty: 0 },
                { id: 'varme30',     label: 'Varmeovn, 30 kw diesel',       price: 500,   unit: 'stk.',  hasQty: true,  defaultQty: 0 },
                { id: 'gasflaske',   label: 'Gasflaske, 11 kg',             price: 220,   unit: 'stk.',  hasQty: true,  defaultQty: 0 },
            ],
        },
        {
            group: 'Lys og dekoration',
            items: [
                { id: 'guirlande',   label: 'Lysguirlande',                 price: 140,   unit: 'stk.',  hasQty: true,  defaultQty: 0 },
                { id: 'led_guirl',   label: 'Lysguirlande LED 7,5 m',       price: 160,   unit: 'stk.',  hasQty: true,  defaultQty: 0 },
                { id: 'uplight',     label: 'Uplight lampe (ADJ Mega)',      price: 32,    unit: 'stk.',  hasQty: true,  defaultQty: 0 },
            ],
        },
        {
            group: 'Opstilling og nedtagning',
            items: [
                { id: 'opned3',      label: 'Op/nedtagning 3 m telt',       price: 400,   unit: 'stk.',  hasQty: false, checkbox: true },
                { id: 'opned6',      label: 'Op/nedtagning 6 m telt',       price: 400,   unit: 'stk.',  hasQty: false, checkbox: true },
                { id: 'opned9',      label: 'Op/nedtagning 9 m telt',       price: 600,   unit: 'stk.',  hasQty: false, checkbox: true },
            ],
        },
        {
            group: 'Udvidelse',
            items: [
                { id: 'ext3',        label: 'Udvidelsesfag 3 m',            price: 600,   unit: 'stk.',  hasQty: true,  defaultQty: 0 },
                { id: 'ext6',        label: 'Udvidelsesfag 6 m',            price: 600,   unit: 'stk.',  hasQty: true,  defaultQty: 0 },
                { id: 'ext9',        label: 'Udvidelsesfag 9 m',            price: 600,   unit: 'stk.',  hasQty: true,  defaultQty: 0 },
            ],
        },
    ];

    /* ────────────────────────────────────────────
       STATE
    ──────────────────────────────────────────── */
    const state = {
        currentStep: 1,
        selectedWidth: null,
        selectedTent: null,
        days: 1,
        accessories: {},   // id → qty (0 = not selected)
        postcode: '',
        deliveryCost: 0,
        deliveryLabel: '',
    };

    /* ────────────────────────────────────────────
       HELPERS
    ──────────────────────────────────────────── */
    function fmt(n) {
        return n.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ' kr';
    }

    function goToStep(n) {
        $('.lpt-step').removeClass('active prev');
        for (let i = 1; i < n; i++) $('#lpt-step-' + i).addClass('prev');
        $('#lpt-step-' + n).addClass('active');
        state.currentStep = n;
        updateProgress(n);
        $('html, body').animate({ scrollTop: $('#lpt-calculator').offset().top - 80 }, 300);
    }

    function updateProgress(step) {
        const pct = ((step - 1) / 4) * 100;
        $('#lpt-progress-bar').css('width', pct + '%');
    }

    /* ────────────────────────────────────────────
       STEP 1 — TELT
    ──────────────────────────────────────────── */
    function initStep1() {
        $('#lpt-width-group').on('click', '.lpt-btn', function () {
            const w = $(this).data('width');
            $('#lpt-width-group .lpt-btn').removeClass('active');
            $(this).addClass('active');
            state.selectedWidth = w;
            state.selectedTent  = null;
            renderLengthDropdown(w);
            $('#lpt-length-wrap').removeClass('lpt-hidden');
            $('#lpt-tent-info').addClass('lpt-hidden').html('');
            $('#lpt-step1-next').addClass('lpt-hidden');
        });

        $('#lpt-length').on('change', function () {
            const id = $(this).val();
            if (!id) {
                state.selectedTent = null;
                $('#lpt-tent-info').addClass('lpt-hidden');
                $('#lpt-step1-next').addClass('lpt-hidden');
                return;
            }
            const tent = findTentById(id);
            if (!tent) return;
            state.selectedTent = tent;
            renderTentInfo(tent);
            $('#lpt-step1-next').removeClass('lpt-hidden');
        });

        $('#lpt-step1-next').on('click', function () {
            if (!state.selectedTent) return;
            goToStep(2);
        });
    }

    function renderLengthDropdown(width) {
        const tents = TENTS[width] || [];
        const $sel  = $('#lpt-length').html('<option value="">— vælg —</option>');
        tents.forEach(t => {
            $sel.append(`<option value="${t.id}">${t.label} — ${fmt(t.price)} / dag</option>`);
        });
    }

    function findTentById(id) {
        for (const list of Object.values(TENTS)) {
            const t = list.find(x => x.id === id);
            if (t) return t;
        }
        return null;
    }

    function renderTentInfo(tent) {
        const html = `
            <div class="lpt-infobox">
                <div class="lpt-infobox-item">📐 <strong>${tent.label}</strong></div>
                <div class="lpt-infobox-item">👥 Kapacitet ca. <strong>${tent.capacity} gæster</strong> siddende</div>
                <div class="lpt-infobox-item">📏 Areal: <strong>${tent.sqm} m²</strong></div>
                <div class="lpt-infobox-item">💰 <strong>${fmt(tent.price)} pr. dag</strong></div>
            </div>`;
        $('#lpt-tent-info').html(html).removeClass('lpt-hidden');
    }

    /* ────────────────────────────────────────────
       STEP 2 — LEJEPERIODE
    ──────────────────────────────────────────── */
    function initStep2() {
        $('#lpt-days-group').on('click', '.lpt-btn', function () {
            $('#lpt-days-group .lpt-btn').removeClass('active');
            $(this).addClass('active');
            state.days = parseInt($(this).data('days'), 10);
        });
        $('#lpt-step2-back').on('click', () => goToStep(1));
        $('#lpt-step2-next').on('click', () => { renderAccessories(); goToStep(3); });
    }

    /* ────────────────────────────────────────────
       STEP 3 — TILBEHØR
    ──────────────────────────────────────────── */
    function renderAccessories() {
        const $grid = $('#lpt-accessories').html('');
        ACCESSORIES.forEach(group => {
            const $g = $(`<div class="lpt-acc-group"><h3>${group.group}</h3><div class="lpt-acc-items"></div></div>`);
            group.items.forEach(item => {
                const qty   = state.accessories[item.id] || (item.checkbox ? 0 : 0);
                const $item = $(`
                    <div class="lpt-acc-item" data-id="${item.id}">
                        <div class="lpt-acc-label">
                            <strong>${item.label}</strong>
                            <span class="lpt-acc-price">${fmt(item.price)} / ${item.unit}</span>
                        </div>
                        <div class="lpt-acc-control">
                            ${item.checkbox
                                ? `<label class="lpt-toggle"><input type="checkbox" class="lpt-acc-check" data-id="${item.id}" ${qty ? 'checked' : ''}> Tilføj</label>`
                                : `<div class="lpt-qty-wrap">
                                       <button type="button" class="lpt-qty-btn lpt-qty-minus" data-id="${item.id}">−</button>
                                       <input type="number" class="lpt-qty-input" data-id="${item.id}" value="${qty}" min="0" max="999">
                                       <button type="button" class="lpt-qty-btn lpt-qty-plus" data-id="${item.id}">+</button>
                                   </div>`
                            }
                        </div>
                    </div>`);
                $g.find('.lpt-acc-items').append($item);
            });
            $grid.append($g);
        });
    }

    function initStep3() {
        $('#lpt-accessories').on('change', '.lpt-acc-check', function () {
            const id = $(this).data('id');
            state.accessories[id] = $(this).is(':checked') ? 1 : 0;
        });
        $('#lpt-accessories').on('input change', '.lpt-qty-input', function () {
            const id  = $(this).data('id');
            const val = Math.max(0, parseInt($(this).val(), 10) || 0);
            $(this).val(val);
            state.accessories[id] = val;
        });
        $('#lpt-accessories').on('click', '.lpt-qty-plus', function () {
            const id  = $(this).data('id');
            const $in = $(`.lpt-qty-input[data-id="${id}"]`);
            const v   = (parseInt($in.val(), 10) || 0) + 1;
            $in.val(v).trigger('change');
        });
        $('#lpt-accessories').on('click', '.lpt-qty-minus', function () {
            const id  = $(this).data('id');
            const $in = $(`.lpt-qty-input[data-id="${id}"]`);
            const v   = Math.max(0, (parseInt($in.val(), 10) || 0) - 1);
            $in.val(v).trigger('change');
        });
        $('#lpt-step3-back').on('click', () => goToStep(2));
        $('#lpt-step3-next').on('click', () => goToStep(4));
    }

    /* ────────────────────────────────────────────
       STEP 4 — LEVERING
    ──────────────────────────────────────────── */
    function initStep4() {
        $('#lpt-postcode').on('input', function () {
            const pc = $(this).val().trim();
            state.postcode = pc;
            evaluateDelivery(pc);
        });
        $('#lpt-step4-back').on('click', () => goToStep(3));
        $('#lpt-step4-next').on('click', () => { renderSummary(); goToStep(5); });
    }

    function evaluateDelivery(pc) {
        const $res = $('#lpt-delivery-result');
        if (pc.length < 4) {
            $res.html('').removeClass('ok other');
            state.deliveryCost  = 0;
            state.deliveryLabel = '';
            return;
        }
        const postcodes = lptConfig.deliveryPostcodes || [];
        if (postcodes.includes(pc)) {
            const cost = lptConfig.deliveryCost || 250;
            state.deliveryCost  = cost;
            state.deliveryLabel = cost + ' kr inkl. moms';
            $res.html(`✅ Levering og afhentning: <strong>${fmt(cost)}</strong>`).addClass('ok').removeClass('other');
        } else {
            state.deliveryCost  = 0;
            state.deliveryLabel = 'Beregnes i kassen';
            $res.html('ℹ️ Levering til dette postnummer beregnes individuelt — se den endelige pris i kassen.').addClass('other').removeClass('ok');
        }
    }

    /* ────────────────────────────────────────────
       STEP 5 — OVERSIGT + BESTIL
    ──────────────────────────────────────────── */
    function calcTotal() {
        const mult     = MULTIPLIERS[state.days] || 1;
        let   tentBase = state.selectedTent ? state.selectedTent.price : 0;
        let   accBase  = 0;
        const lines    = [];

        // Telt linje
        lines.push({
            name:   state.selectedTent ? state.selectedTent.label : '—',
            detail: fmt(tentBase) + ' / dag × ' + state.days + ' dag(e) × ' + mult,
            total:  tentBase * mult,
            isTent: true,
        });

        // Tilbehør linjer
        ACCESSORIES.forEach(group => {
            group.items.forEach(item => {
                const qty = state.accessories[item.id] || 0;
                if (!qty) return;
                const lineTotal = item.price * qty * mult;
                accBase += lineTotal;
                lines.push({
                    name:   item.label,
                    detail: qty + ' × ' + fmt(item.price) + ' × ' + mult,
                    total:  lineTotal,
                });
            });
        });

        const subtotal = tentBase * mult + accBase;
        const delivery = state.deliveryCost || 0;
        const grand    = subtotal + delivery;

        return { lines, subtotal, delivery, grand, mult };
    }

    function renderSummary() {
        const { lines, subtotal, delivery, grand } = calcTotal();
        const $sum = $('#lpt-summary').html('');
        const $tot = $('#lpt-total');

        lines.forEach(l => {
            $sum.append(`
                <div class="lpt-summary-line ${l.isTent ? 'lpt-line-tent' : ''}">
                    <span class="lpt-line-name">${l.name}</span>
                    <span class="lpt-line-detail">${l.detail}</span>
                    <span class="lpt-line-total">${fmt(l.total)}</span>
                </div>`);
        });

        if (state.deliveryLabel) {
            $sum.append(`
                <div class="lpt-summary-line lpt-line-delivery">
                    <span class="lpt-line-name">Levering og afhentning</span>
                    <span class="lpt-line-detail">${state.postcode}</span>
                    <span class="lpt-line-total">${state.deliveryCost ? fmt(state.deliveryCost) : state.deliveryLabel}</span>
                </div>`);
        }

        $tot.html(`
            <div class="lpt-total-inner">
                <span>Estimeret total inkl. moms</span>
                <strong>${fmt(grand)}</strong>
            </div>
            ${delivery === 0 && state.postcode.length === 4 ? '<p class="lpt-total-note">+ levering (beregnes i kassen)</p>' : ''}
        `);
    }

    function initStep5() {
        $('#lpt-step5-back').on('click', () => goToStep(4));
        $('#lpt-add-to-cart').on('click', addToCart);
    }

    function addToCart() {
        if (!lptConfig.productId) {
            showCartMessage('error', 'Produktet er ikke konfigureret endnu. Kontakt os venligst direkte.');
            return;
        }

        const { lines, subtotal, grand } = calcTotal();

        // Byg summary-tekst til ordren
        const summaryLines = lines.map(l => `${l.name}: ${l.detail} = ${fmt(l.total)}`);
        if (state.deliveryLabel) summaryLines.push('Levering: ' + state.deliveryLabel);

        const package_data = {
            tent:               state.selectedTent ? state.selectedTent.label : '',
            days:               state.days,
            price_excl_delivery: subtotal,
            total:              grand,
            delivery_label:     state.deliveryLabel || '',
            lines:              lines.map(l => ({ name: l.name, detail: l.detail })),
            summary:            summaryLines.join('\n'),
        };

        const $btn = $('#lpt-add-to-cart').prop('disabled', true).text('Tilføjer...');

        $.post(lptConfig.ajaxUrl, {
            action:      'lpt_add_to_cart',
            nonce:       lptConfig.nonce,
            lpt_package: JSON.stringify(package_data),
        })
        .done(function (res) {
            if (res.success) {
                showCartMessage('success', '✅ Tilføjet til kurv! <a href="' + lptConfig.cartUrl + '">Gå til kurv →</a>');
            } else {
                showCartMessage('error', res.data.message || 'Noget gik galt. Prøv igen.');
            }
        })
        .fail(function () {
            showCartMessage('error', 'Netværksfejl — prøv igen eller kontakt os direkte.');
        })
        .always(function () {
            $btn.prop('disabled', false).text('🛒 Læg i kurv');
        });
    }

    function showCartMessage(type, html) {
        $('#lpt-cart-message')
            .removeClass('lpt-hidden lpt-msg-success lpt-msg-error')
            .addClass(type === 'success' ? 'lpt-msg-success' : 'lpt-msg-error')
            .html(html);
    }

    /* ────────────────────────────────────────────
       INIT
    ──────────────────────────────────────────── */
    $(function () {
        if (!$('#lpt-calculator').length) return;

        initStep1();
        initStep2();
        initStep3();
        initStep4();
        initStep5();
        updateProgress(1);
    });

})(jQuery);
