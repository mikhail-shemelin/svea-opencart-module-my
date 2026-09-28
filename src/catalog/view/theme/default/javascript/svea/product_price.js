(function ($, window, document) {
    'use strict';

    function sameAmount(left, right) {
        return Math.abs(Number(left) - Number(right)) < 0.00001;
    }

    function Widget(element) {
        this.element = element;
        this.$element = $(element);
        this.$trigger = this.$element.find('.svea-product-price__trigger');
        this.$dialog = this.$element.find('.svea-product-price__dialog');
        this.$body = this.$element.find('.svea-product-price__body');
        this.$close = this.$element.find('.svea-product-price__close');
        this.productId = Number(this.$element.data('product-id'));
        this.currency = String(this.$element.data('currency'));
        this.calculationUrl = String(this.$element.data('calculation-url'));
        this.text = {
            loading: String(this.$element.data('loading-text')),
            error: String(this.$element.data('error-text')),
            unavailable: String(this.$element.data('unavailable-text')),
            retry: String(this.$element.data('retry-text'))
        };
        this.livePrice = null;
        this.choiceRevision = 0;
        this.expiredRevision = null;
        this.pricePending = true;
        this.priceTimer = null;
        this.apiRequestId = 0;
        this.apiRequest = null;
        this.context = null;
        this.result = null;
        this.returnFocus = null;
        this.destroyed = false;
        // Journal can place product content inside transformed containers. Move the
        // fixed dialog to the body so its viewport positioning remains correct.
        this.$dialog.appendTo(document.body);
        this.bindUi();
        this.renderLoading();
        this.watchRemoval();
    }

    Widget.prototype.bindUi = function () {
        var widget = this;

        this.$trigger.on('click.sveaProductPrice', function () {
            widget.open();
        });
        this.$close.on('click.sveaProductPrice', function () {
            widget.close();
        });
        this.$body.on('click.sveaProductPrice', '.svea-product-price__retry', function () {
            widget.retry();
        });
        $(document).on('keydown.sveaProductPrice' + this.productId, function (event) {
            if (event.key === 'Escape' && !widget.$dialog.prop('hidden')) {
                widget.close();
            }
        });
        $(document).on('mousedown.sveaProductPrice' + this.productId, function (event) {
            if (!widget.$dialog.prop('hidden')
                && !widget.$dialog.is(event.target)
                && widget.$dialog.has(event.target).length === 0
                && !widget.$trigger.is(event.target)
                && widget.$trigger.has(event.target).length === 0) {
                widget.close();
            }
        });
    };

    Widget.prototype.watchRemoval = function () {
        var widget = this;

        if (!window.MutationObserver || !document.body) {
            return;
        }

        this.observer = new window.MutationObserver(function () {
            if (!document.documentElement.contains(widget.element)) {
                widget.destroy();
            }
        });
        this.observer.observe(document.body, { childList: true, subtree: true });
    };

    Widget.prototype.open = function () {
        if (this.destroyed) {
            return;
        }

        this.returnFocus = document.activeElement;
        this.$dialog.prop('hidden', false);
        this.$trigger.attr('aria-expanded', 'true');
        this.$close.trigger('focus');
    };

    Widget.prototype.close = function () {
        this.$dialog.prop('hidden', true);
        this.$trigger.attr('aria-expanded', 'false');

        if (this.returnFocus && document.documentElement.contains(this.returnFocus)) {
            $(this.returnFocus).trigger('focus');
        } else {
            this.$trigger.trigger('focus');
        }
    };

    Widget.prototype.preserveFocusBeforeBodyUpdate = function () {
        var activeElement = document.activeElement;
        var bodyElement = this.$body.get(0);

        if (!this.$dialog.prop('hidden')
            && activeElement
            && bodyElement
            && bodyElement.contains(activeElement)) {
            this.$close.trigger('focus');
        }
    };

    Widget.prototype.renderLoading = function () {
        var $state = $('<div class="svea-product-price__state svea-product-price__state--loading" role="status"></div>');
        $state.append('<span class="svea-product-price__spinner" aria-hidden="true"></span>');
        $state.append($('<span></span>').text(this.text.loading));
        this.preserveFocusBeforeBodyUpdate();
        this.$body.empty().append($state);
    };

    Widget.prototype.renderError = function () {
        var $state = $('<div class="svea-product-price__state svea-product-price__state--error" role="alert"></div>');
        $state.append($('<span></span>').text(this.text.error));
        $state.append($('<button type="button" class="svea-product-price__retry"></button>').text(this.text.retry));
        this.preserveFocusBeforeBodyUpdate();
        this.$body.empty().append($state);
    };

    Widget.prototype.renderUnavailable = function () {
        this.preserveFocusBeforeBodyUpdate();
        this.$body.empty().append(
            $('<div class="svea-product-price__state svea-product-price__state--unavailable" role="status"></div>').text(this.text.unavailable)
        );
    };

    Widget.prototype.renderResult = function () {
        if (!this.result) {
            this.renderLoading();
            return;
        }

        if (this.result.status === 'success') {
            this.preserveFocusBeforeBodyUpdate();
            this.$body.html(this.result.html);
        } else if (this.result.status === 'unavailable') {
            this.renderUnavailable();
        } else {
            this.renderError();
        }
    };

    Widget.prototype.invalidatePrice = function () {
        var widget = this;

        if (this.destroyed) {
            return;
        }

        this.choiceRevision += 1;
        this.expiredRevision = null;
        this.pricePending = true;
        this.renderLoading();
        window.clearTimeout(this.priceTimer);
        this.priceTimer = window.setTimeout(function () {
            if (!widget.destroyed && widget.pricePending) {
                widget.expiredRevision = widget.choiceRevision;
                widget.result = { status: 'error' };
                widget.renderError();
            }
        }, 10000);
    };

    Widget.prototype.getChoiceRevision = function () {
        return this.choiceRevision;
    };

    Widget.prototype.isPriceResponseCurrent = function (revision) {
        return !this.destroyed
            && Number(revision) === this.choiceRevision
            && this.expiredRevision !== this.choiceRevision;
    };

    Widget.prototype.acceptPrice = function (context) {
        var amount = Number(context.amount);
        var unchanged;

        if (!this.isPriceResponseCurrent(context.revision)
            || Number(context.product_id) !== this.productId
            || String(context.currency) !== this.currency
            || !isFinite(amount)
            || amount <= 0) {
            return;
        }

        window.clearTimeout(this.priceTimer);
        this.priceTimer = null;
        this.pricePending = false;
        unchanged = this.context
            && sameAmount(this.context.amount, amount)
            && this.context.currency === String(context.currency);

        if (unchanged) {
            if (this.result) {
                this.renderResult();
            } else {
                this.renderLoading();
            }
            return;
        }

        this.context = {
            amount: amount,
            currency: String(context.currency),
            product_id: this.productId
        };
        this.result = null;
        this.startCalculation();
    };

    Widget.prototype.startCalculation = function () {
        var widget = this;
        var requestId;
        var requestContext;

        if (!this.context || this.pricePending || this.destroyed) {
            return;
        }

        this.apiRequestId += 1;
        requestId = this.apiRequestId;
        requestContext = {
            amount: this.context.amount,
            currency: this.context.currency,
            product_id: this.context.product_id
        };
        this.result = null;
        this.renderLoading();

        if (this.apiRequest) {
            this.apiRequest.abort();
        }

        this.apiRequest = $.ajax({
            url: this.calculationUrl,
            type: 'POST',
            dataType: 'json',
            timeout: 10000,
            data: requestContext
        }).done(function (response) {
            widget.receiveCalculation(requestId, requestContext, response);
        }).fail(function (xhr, status) {
            if (status !== 'abort') {
                widget.receiveCalculation(requestId, requestContext, { status: 'error' });
            }
        }).always(function () {
            if (requestId === widget.apiRequestId) {
                widget.apiRequest = null;
            }
        });
    };

    Widget.prototype.receiveCalculation = function (requestId, requestContext, response) {
        if (this.destroyed
            || requestId !== this.apiRequestId
            || this.expiredRevision === this.choiceRevision
            || !this.context
            || this.context.product_id !== requestContext.product_id
            || this.context.currency !== requestContext.currency
            || !sameAmount(this.context.amount, requestContext.amount)) {
            return;
        }

        if (response && response.status === 'success' && typeof response.html === 'string') {
            this.result = { status: 'success', html: response.html };
        } else if (response && response.status === 'unavailable') {
            this.result = { status: 'unavailable' };
        } else {
            this.result = { status: 'error' };
        }

        if (!this.pricePending) {
            this.renderResult();
        }
    };

    Widget.prototype.retry = function () {
        if (this.destroyed) {
            return;
        }

        if (this.pricePending || !this.context || this.expiredRevision === this.choiceRevision) {
            this.renderLoading();

            if (this.livePrice) {
                this.livePrice.updatePrice(0);
            }
            return;
        }

        this.startCalculation();
    };

    Widget.prototype.bindLivePrice = function (livePrice) {
        this.livePrice = livePrice;
    };

    Widget.prototype.destroy = function () {
        if (this.destroyed) {
            return;
        }

        this.destroyed = true;
        window.clearTimeout(this.priceTimer);

        if (this.apiRequest) {
            this.apiRequest.abort();
        }
        if (this.observer) {
            this.observer.disconnect();
        }

        $(document).off('.sveaProductPrice' + this.productId);
        this.$element.off('.sveaProductPrice');
        this.$body.off('.sveaProductPrice');
        this.$dialog.remove();
    };

    window.SveaProductPrice = {
        get: function (productId) {
            var element = $('[data-svea-product-price][data-product-id="' + Number(productId) + '"]').get(0);
            return element ? $(element).data('sveaProductPrice') : null;
        }
    };

    $(function () {
        $('[data-svea-product-price]').each(function () {
            var widget = new Widget(this);
            $(this).data('sveaProductPrice', widget);
        });
    });
}(jQuery, window, document));
