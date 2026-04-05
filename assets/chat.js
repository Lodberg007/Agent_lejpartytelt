/**
 * LPT Chat — chat.js
 * AI-lejerådgiver for Lejpartytelt.dk
 */
(function ($) {
    'use strict';

    const OFFER_START  = '[TILBUD_START]';
    const OFFER_END    = '[TILBUD_SLUT]';
    const IMG_START    = '[BILLEDER_START]';
    const IMG_END      = '[BILLEDER_SLUT]';

    function dayMultiplier(days) {
        if (days <= 1) return 1.0;
        if (days === 2) return 1.4;
        if (days === 3) return 1.7;
        return 1.7 + (days - 3) * 0.25;
    }

    let history = [];

    /* ── INIT ── */
    $(function () {
        // Chat
        if ($('#lpt-chat').length) {
            $('#lpt-chat-send').on('click', sendMessage);
            $('#lpt-chat-input').on('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage();
                }
            });
        }

        // Tilbudsoversigt lytter på event fra chatten
        if ($('#lpt-live-summary').length) {
            $(document).on('lpt:offer', function (e, offer) {
                updateSummaryPanel(offer);
            });
        }

        // Produktbilleder lytter på event fra chatten
        if ($('#lpt-visual-panel').length) {
            $(document).on('lpt:offer', function (e, offer) {
                updateVisualPanel(offer);
            });
        }
    });

    /* ── SEND BESKED ── */
    function sendMessage() {
        const $input = $('#lpt-chat-input');
        const text   = $input.val().trim();
        if (!text) return;

        $input.val('').prop('disabled', true);
        $('#lpt-chat-send').prop('disabled', true);

        appendMessage('user', text);
        showTyping();

        $.post(lptChatConfig.ajaxUrl, {
            action:  'lpt_chat_message',
            nonce:   lptChatConfig.nonce,
            message: text,
            history: JSON.stringify(history),
        })
        .done(function (res) {
            removeTyping();
            if (res.success) {
                history = res.data.history || [];
                renderAgentMessage(res.data.message);
            } else {
                appendMessage('agent', 'Beklager, der opstod en fejl: ' + (res.data.message || 'Prøv igen.'));
            }
        })
        .fail(function () {
            removeTyping();
            appendMessage('agent', 'Netværksfejl — prøv igen om lidt.');
        })
        .always(function () {
            $input.prop('disabled', false).focus();
            $('#lpt-chat-send').prop('disabled', false);
        });
    }

    /* ── RENDER AGENT SVAR ── */
    function renderAgentMessage(raw) {
        // Håndter tilbud
        const offerStart = raw.indexOf(OFFER_START);
        const offerEnd   = raw.indexOf(OFFER_END);
        if (offerStart !== -1 && offerEnd !== -1) {
            const textBefore = raw.substring(0, offerStart).trim();
            const jsonStr    = raw.substring(offerStart + OFFER_START.length, offerEnd).trim();
            const textAfter  = raw.substring(offerEnd + OFFER_END.length).trim();
            if (textBefore) appendMessage('agent', textBefore);
            try {
                const offer = JSON.parse(jsonStr);
                appendOffer(offer);
            } catch (e) {
                appendMessage('agent', raw.replace(OFFER_START, '').replace(OFFER_END, '').trim());
            }
            if (textAfter) appendMessage('agent', textAfter);
            return;
        }

        // Håndter produktbilleder
        const imgStart = raw.indexOf(IMG_START);
        const imgEnd   = raw.indexOf(IMG_END);
        if (imgStart !== -1 && imgEnd !== -1) {
            const textBefore = raw.substring(0, imgStart).trim();
            const jsonStr    = raw.substring(imgStart + IMG_START.length, imgEnd).trim();
            const textAfter  = raw.substring(imgEnd + IMG_END.length).trim();
            if (textBefore) appendMessage('agent', textBefore);
            try {
                const data = JSON.parse(jsonStr);
                showProductImages(data.products || []);
            } catch (e) {}
            if (textAfter) appendMessage('agent', textAfter);
            return;
        }

        appendMessage('agent', raw);
    }

    /* ── VIS PRODUKTBILLEDER I VISUELT PANEL ── */
    function showProductImages(productNames) {
        if (!productNames.length) return;
        // Byg fake offer-lines så updateVisualPanel kan genbruge eksisterende kode
        const lines = productNames.map(function(name) {
            const imgData = findImage(name);
            return { name: name, unitPrice: imgData ? (imgData.price || 0) : 0 };
        });
        updateVisualPanel({ lines: lines });
    }

    /* ── TILGÆNGELIGHEDS-TJEK ── */
    function checkAvailability(offer) {
        if (!offer.start_date || !offer.end_date) return; // Ingen datoer — skip
        const lines = (offer.lines || []).map(l => ({ name: l.name, qty: l.qty }));

        $.post(lptChatConfig.ajaxUrl, {
            action:     'lpt_check_availability',
            nonce:      lptChatConfig.nonce,
            lines:      JSON.stringify(lines),
            start_date: offer.start_date,
            end_date:   offer.end_date,
        }).done(function(res) {
            if (!res.success || !res.data) return;
            const data = res.data;
            if (data.skipped || data.ok) return; // Alt ledigt eller tjek sprunget over

            // Byg besked til Claude om utilgængelighed
            const unavail = data.unavailable || [];
            if (unavail.length === 0) return;

            let msg = 'Tilgængelhedstjek: ';
            const parts = unavail.map(function(u) {
                let s = '"' + u.name + '" er ikke ledigt i perioden ' + offer.start_date + ' til ' + offer.end_date;
                if (u.alternative) s += '. Alternativ: "' + u.alternative + '"';
                return s;
            });
            msg += parts.join('; ') + '. Foreslå alternativerne og præsentér et opdateret tilbud med de ledige produkter.';

            // Indsæt automatisk som systembesked og send til Claude
            appendMessage('agent', '⚠️ Jeg tjekker tilgængelighed på dine ønsker...');
            sendAutoMessage(msg);
        });
    }

    function sendAutoMessage(text) {
        showTyping();
        $.post(lptChatConfig.ajaxUrl, {
            action:  'lpt_chat_message',
            nonce:   lptChatConfig.nonce,
            message: text,
            history: JSON.stringify(history),
        }).done(function(res) {
            removeTyping();
            if (res.success) {
                history = res.data.history || [];
                renderAgentMessage(res.data.message);
            }
        }).fail(function() {
            removeTyping();
        });
    }

    /* ── TILBUDS-KORT ── */
    function appendOffer(offer) {
        const mult   = offer.multiplier || 1;
        const days   = offer.days || 1;
        const dayLabel = days === 1 ? '1 dag' : days + ' dage';

        let linesHtml = '';
        (offer.lines || []).forEach(function (l) {
            const imgData = findImage(l.name);
            const imgHtml = (imgData && imgData.url)
                ? `<img src="${esc(imgData.url)}" alt="${esc(l.name)}" class="lpt-offer-img">`
                : '';
            linesHtml += `
                <div class="lpt-offer-line">
                    ${imgHtml}
                    <span class="lpt-offer-name">${esc(l.name)}${l.qty > 1 ? ' × ' + l.qty : ''}</span>
                    <span class="lpt-offer-price">${fmt(l.lineTotal)} kr</span>
                </div>`;
        });

        if (offer.delivery > 0) {
            linesHtml += `
                <div class="lpt-offer-line lpt-offer-delivery">
                    <span class="lpt-offer-name">Levering og afhentning</span>
                    <span class="lpt-offer-price">${fmt(offer.delivery)} kr</span>
                </div>`;
        }

        const hasDates   = !!(offer.start_date && offer.end_date);
        const dateLabel  = hasDates
            ? `<div class="lpt-offer-dates">📅 ${esc(offer.start_date)} → ${esc(offer.end_date)}</div>`
            : `<div class="lpt-offer-dates lpt-offer-dates-missing">⚠️ Dato ikke angivet — angiv venligst dato for at reservere</div>`;

        const card = $(`
            <div class="lpt-offer-card">
                <div class="lpt-offer-header">
                    <span class="lpt-offer-title">Tilbud — ${esc(dayLabel)}</span>
                    <span class="lpt-offer-mult">× ${mult.toFixed(1).replace('.', ',')}</span>
                </div>
                ${dateLabel}
                <div class="lpt-offer-lines">${linesHtml}</div>
                <div class="lpt-offer-total">
                    <span>Total inkl. moms</span>
                    <strong>${fmt(offer.total)} kr</strong>
                </div>
                <div class="lpt-offer-note">Ingen depositum. Lejekontrakt sendes efter booking.</div>
                <button type="button" class="lpt-offer-btn" ${hasDates ? '' : 'disabled'}>
                    🛒 Læg tilbud i kurv
                </button>
                <div class="lpt-offer-msg lpt-hidden"></div>
            </div>`);

        card.find('.lpt-offer-btn').on('click', function () {
            addOfferToCart($(this), offer);
        });

        // Tilgængeligheds-tjek (asynkront — uden at blokere UI)
        if (hasDates) {
            checkAvailability(offer);
        }

        // Vis tilbudskortet i summary-panelet hvis det findes — ellers i chatten
        if ($('#lpt-live-summary-body').length) {
            $(document).trigger('lpt:offer', [offer]);
            appendMessage('agent', '✅ Dit tilbud er klar — se det i tilbudspanelet til højre.');
        } else {
            const $wrap = $('<div class="lpt-msg lpt-msg-agent lpt-msg-offer"></div>').append(card);
            $('#lpt-chat-messages').append($wrap);
            scrollToBottom();
        }
    }

    /* ── LÆGG I KURV (individuelle produkter) ── */
    function addOfferToCart($btn, offer) {
        $btn.prop('disabled', true).text('Tilføjer...');

        $.post(lptChatConfig.ajaxUrl, {
            action: 'lpt_add_items_to_cart',
            nonce:  lptChatConfig.nonce,
            offer:  JSON.stringify(offer),
        })
        .done(function (res) {
            if (res.success) {
                const $msgEl = $btn.closest('.lpt-offer-card').find('.lpt-offer-msg');
                let html = 'Tilføjet! <a href="' + lptChatConfig.cartUrl + '">Gå til kurv &rarr;</a>';
                if (res.data.warning) html += '<br><small>' + esc(res.data.warning) + '</small>';
                $msgEl.removeClass('lpt-hidden lpt-offer-msg-err').addClass('lpt-offer-msg-ok').html(html);
                $btn.text('✅ Tilføjet');
            } else {
                showOfferError($btn, res.data.message || 'Noget gik galt.');
            }
        })
        .fail(function () {
            showOfferError($btn, 'Netværksfejl — prøv igen.');
        });
    }

    function showOfferError($btn, msg) {
        $btn.prop('disabled', false).text('🛒 Læg tilbud i kurv');
        $btn.closest('.lpt-offer-card').find('.lpt-offer-msg')
            .removeClass('lpt-hidden lpt-offer-msg-ok')
            .addClass('lpt-offer-msg-err')
            .text(msg);
    }

    /* ── TYPING INDICATOR ── */
    function showTyping() {
        const $t = $('<div class="lpt-msg lpt-msg-agent lpt-typing" id="lpt-typing"><div class="lpt-msg-bubble"><span></span><span></span><span></span></div></div>');
        $('#lpt-chat-messages').append($t);
        scrollToBottom();
    }
    function removeTyping() { $('#lpt-typing').remove(); }

    /* ── APPEND PLAIN MESSAGE ── */
    function appendMessage(role, text) {
        const cls  = role === 'user' ? 'lpt-msg-user' : 'lpt-msg-agent';
        const html = markdownToHtml(text);
        const $msg = $('<div class="lpt-msg ' + cls + '"><div class="lpt-msg-bubble"></div></div>');
        $msg.find('.lpt-msg-bubble').html(html);
        $('#lpt-chat-messages').append($msg);
        scrollToBottom();
    }

    /* ── HELPERS ── */
    function scrollToBottom() {
        const $msgs = $('#lpt-chat-messages');
        $msgs.scrollTop($msgs[0].scrollHeight);
    }

    function fmt(n) {
        return parseFloat(n).toLocaleString('da-DK', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    }

    function esc(str) {
        return $('<div>').text(str || '').html();
    }

    /* ── LIVE TILBUDSOVERSIGT (højre panel) ── */
    function updateSummaryPanel(offer) {
        const $body = $('#lpt-live-summary-body');
        if (!$body.length) return;

        const mult     = offer.multiplier || 1;
        const days     = offer.days || 1;
        const dayLabel = days === 1 ? '1 dag' : days + ' dage';
        const multFmt  = mult.toFixed(2).replace('.', ',').replace(/,?0+$/, '').replace(',', ',');

        let rowsHtml = '';
        (offer.lines || []).forEach(function (l) {
            const imgData = findImage(l.name);
            const imgHtml = (imgData && imgData.url)
                ? `<img src="${esc(imgData.url)}" class="lpt-sum-img" alt="">`
                : '<span class="lpt-sum-img-placeholder"></span>';
            const nameHtml = (imgData && imgData.link)
                ? `<a href="${esc(imgData.link)}" target="_blank" rel="noopener">${esc(l.name)}</a>`
                : esc(l.name);
            rowsHtml += `
                <tr>
                    <td class="lpt-sum-name">${imgHtml}${nameHtml}${l.qty > 1 ? '<em> × ' + l.qty + '</em>' : ''}</td>
                    <td class="lpt-sum-price">${fmt(l.unitPrice)} kr</td>
                    <td class="lpt-sum-total">${fmt(l.lineTotal)} kr</td>
                </tr>`;
        });

        if (offer.delivery > 0) {
            rowsHtml += `
                <tr class="lpt-sum-delivery">
                    <td colspan="2" class="lpt-sum-name">🚚 Levering og afhentning</td>
                    <td class="lpt-sum-total">${fmt(offer.delivery)} kr</td>
                </tr>`;
        }

        const hasDates = !!(offer.start_date && offer.end_date);
        const dateSpan = hasDates
            ? `<span>📅 ${esc(offer.start_date)} → ${esc(offer.end_date)}</span>`
            : `<span style="color:#d97706">⚠️ Dato mangler</span>`;

        $body.removeClass('lpt-live-summary-empty').html(`
            <div class="lpt-sum-meta">
                <span>⏱ ${esc(dayLabel)}</span>
                <span>× ${esc(String(mult.toFixed(1)))}</span>
                ${offer.tent ? `<span>🏕 ${esc(offer.tent)}</span>` : ''}
                ${dateSpan}
            </div>
            <table class="lpt-sum-table">
                <thead>
                    <tr>
                        <th>Produkt</th>
                        <th>Pris/dag</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>${rowsHtml}</tbody>
            </table>
            <div class="lpt-sum-grand">
                <span>Total inkl. moms</span>
                <strong>${fmt(offer.total)} kr</strong>
            </div>
            ${offer.delivery === 0 && offer.deliveryNote ? `<p class="lpt-sum-note">${esc(offer.deliveryNote)}</p>` : ''}
            <div class="lpt-sum-actions">
                <button type="button" class="lpt-sum-cart-btn" ${hasDates ? '' : 'disabled'}>
                    🛒 Læg tilbud i kurv
                </button>
                ${!hasDates ? '<p style="font-size:0.8rem;color:#d97706;margin:4px 0 0">Angiv dato i chatten for at aktivere kurv-knappen</p>' : ''}
                <div class="lpt-sum-msg lpt-hidden"></div>
            </div>
        `);

        // Kurv-knap i summary-panel
        $body.find('.lpt-sum-cart-btn').on('click', function () {
            addOfferToCartFromSummary($(this), offer);
        });
    }

    /* ── VISUELT PRODUKTPANEL (højre) — SLIDESHOW ── */
    let slideshowTimer = null;
    let slideshowIndex = 0;
    let slideshowItems = [];

    function updateVisualPanel(offer) {
        const $body = $('#lpt-visual-body');
        if (!$body.length) return;

        const lines = offer.lines || [];
        if (lines.length === 0) return;

        // Byg liste af produkter med billeddata
        slideshowItems = [];
        lines.forEach(function (l) {
            const imgData = findImage(l.name);
            slideshowItems.push({
                name:  l.name,
                price: l.unitPrice || 0,
                url:   imgData && imgData.url  ? imgData.url  : '',
                link:  imgData && imgData.link ? imgData.link : '',
            });
        });

        if (slideshowItems.length === 0) return;

        // Stop evt. kørende slideshow
        if (slideshowTimer) { clearInterval(slideshowTimer); slideshowTimer = null; }
        slideshowIndex = 0;

        // Opbyg slideshow-container
        $body.removeClass('lpt-visual-empty').html(`
            <div class="lpt-slide-wrap">
                <div class="lpt-slide-img-wrap">
                    <img class="lpt-slide-img" src="" alt="">
                    <div class="lpt-slide-no-img lpt-hidden">📦</div>
                </div>
                <div class="lpt-slide-name"></div>
                <div class="lpt-slide-price"></div>
                <div class="lpt-slide-dots"></div>
            </div>
        `);

        // Dots
        if (slideshowItems.length > 1) {
            let dots = '';
            slideshowItems.forEach(function(_, i) {
                dots += `<span class="lpt-slide-dot${i === 0 ? ' active' : ''}" data-i="${i}"></span>`;
            });
            $body.find('.lpt-slide-dots').html(dots);
            $body.find('.lpt-slide-dots').on('click', '.lpt-slide-dot', function() {
                slideshowIndex = parseInt($(this).data('i'));
                renderSlide($body, slideshowIndex);
                resetTimer($body);
            });
        }

        renderSlide($body, 0);

        // Start slideshow kun hvis mere end 1 produkt
        if (slideshowItems.length > 1) {
            slideshowTimer = setInterval(function() {
                slideshowIndex = (slideshowIndex + 1) % slideshowItems.length;
                renderSlide($body, slideshowIndex);
            }, 3000);
        }
    }

    function renderSlide($body, index) {
        const item = slideshowItems[index];
        if (!item) return;

        const $img    = $body.find('.lpt-slide-img');
        const $noImg  = $body.find('.lpt-slide-no-img');
        const $name   = $body.find('.lpt-slide-name');
        const $price  = $body.find('.lpt-slide-price');

        // Pak billede ud af evt. tidligere anchor og sæt nyt
        if ($img.parent().is('a')) $img.unwrap();

        if (item.url) {
            $img.attr('src', item.url).attr('alt', item.name).removeClass('lpt-hidden');
            $noImg.addClass('lpt-hidden');
            if (item.link) {
                $img.wrap(`<a href="${esc(item.link)}" target="_blank" rel="noopener"></a>`);
            }
        } else {
            $img.addClass('lpt-hidden');
            $noImg.removeClass('lpt-hidden');
        }

        if (item.link) {
            $name.html(`<a href="${esc(item.link)}" target="_blank" rel="noopener">${esc(item.name)}</a>`);
        } else {
            $name.text(item.name);
        }
        $price.text(item.price > 0 ? fmt(item.price) + ' kr/dag' : '');

        // Opdater dots
        $body.find('.lpt-slide-dot').removeClass('active').filter(`[data-i="${index}"]`).addClass('active');
    }

    function resetTimer($body) {
        if (slideshowTimer) { clearInterval(slideshowTimer); }
        if (slideshowItems.length > 1) {
            slideshowTimer = setInterval(function() {
                slideshowIndex = (slideshowIndex + 1) % slideshowItems.length;
                renderSlide($body, slideshowIndex);
            }, 3000);
        }
    }

    function addOfferToCartFromSummary($btn, offer) {
        $btn.prop('disabled', true).text('Tilføjer...');
        $.post(lptChatConfig.ajaxUrl, {
            action: 'lpt_add_items_to_cart',
            nonce:  lptChatConfig.nonce,
            offer:  JSON.stringify(offer),
        })
        .done(function (res) {
            const $msg = $btn.siblings('.lpt-sum-msg');
            if (res.success) {
                let html = '✅ Tilføjet! <a href="' + lptChatConfig.cartUrl + '">Gå til kurv →</a>';
                if (res.data.warning) html += '<br><small>' + esc(res.data.warning) + '</small>';
                $msg.removeClass('lpt-hidden lpt-sum-msg-err').addClass('lpt-sum-msg-ok').html(html);
                $btn.text('✅ Tilføjet');
            } else {
                $msg.removeClass('lpt-hidden lpt-sum-msg-ok').addClass('lpt-sum-msg-err')
                    .text(res.data.message || 'Noget gik galt.');
                $btn.prop('disabled', false).text('🛒 Læg tilbud i kurv');
            }
        })
        .fail(function () {
            $btn.prop('disabled', false).text('🛒 Læg tilbud i kurv');
        });
    }

    function normalizeName(str) {
        return str.toLowerCase()
            .replace(/[,\.]/g, ' ')   // komma og punktum → mellemrum
            .replace(/\s+/g, ' ')     // flere mellemrum → ét
            .replace(/×/g, 'x')       // × → x
            .trim();
    }

    function findImage(productName) {
        const images = (lptChatConfig.productImages) || {};
        // 1. Eksakt match
        if ( images[productName] ) return images[productName];
        // 2. Case-insensitivt
        const lower = productName.toLowerCase();
        for ( const [name, data] of Object.entries(images) ) {
            if ( name.toLowerCase() === lower ) return data;
        }
        // 3. Normaliseret match (ignorer kommaer, punktum, mellemrum)
        const norm = normalizeName(productName);
        for ( const [name, data] of Object.entries(images) ) {
            if ( normalizeName(name) === norm ) return data;
        }
        // 4. Delvist normaliseret match
        for ( const [name, data] of Object.entries(images) ) {
            const n = normalizeName(name);
            if ( n.includes(norm) || norm.includes(n) ) return data;
        }
        return null;
    }

    function markdownToHtml(text) {
        // Bold
        text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        // Italic
        text = text.replace(/\*(.*?)\*/g, '<em>$1</em>');
        // Line breaks
        text = text.replace(/\n/g, '<br>');
        // Simple lists
        text = text.replace(/<br>[-•]\s+/g, '<br>• ');
        // Escape XSS — we already control content from Claude
        return text;
    }

})(jQuery);
