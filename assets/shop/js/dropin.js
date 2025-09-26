(() => {
    const instantiate = async ($container) => {

        let checkout = null;
        let configuration = {};
        let $form = $container.closest('form');
        const RATEPAY_STORAGE_KEY = 'ratepay_device_fingerprint';
        const RATEPAY_BUYER_DEVICE_KEY = 'ratepay_buyer_device';
        let ratepayFingerprintState = {
            fingerprint: null,
            checkoutId: null
        };

        const { AdyenCheckout, Dropin, Card, RatePay } = window.AdyenWeb;

        // Generate a stable device identifier for this browser/device
        const generateDeviceId = () => {
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            ctx.textBaseline = 'top';
            ctx.font = '14px Arial';
            ctx.fillText('Device fingerprint', 2, 2);

            return btoa(JSON.stringify({
                userAgent: navigator.userAgent,
                language: navigator.language,
                platform: navigator.platform,
                screen: `${screen.width}x${screen.height}x${screen.colorDepth}`,
                timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
                canvas: canvas.toDataURL(),
                cookieEnabled: navigator.cookieEnabled
            })).replace(/[^a-zA-Z0-9]/g, '').substring(0, 32);
        };

        const _toggleLoader = (show) => {
            const $form = $container.closest('form');
            show ? $form.classList.add('loading') : $form.classList.remove('loading');
        }

        const _loadConfiguration = async (url) => {
            _toggleLoader(true);
            const request = await fetch(url);
            const configuration = await request.json();
            _toggleLoader(false);

            if (typeof configuration['redirect'] == 'string') {
                _toggleLoader(true);
                window.location.replace(configuration['redirect']);
            }

            return configuration;
        }

        const _showErrorMessage = (message) => {
            _clearErrorMessage();

            const errorElement = document.createElement('div');
            errorElement.className = 'adyen-payment-error';
            errorElement.innerHTML = `
                <span class="error-message">${message}</span>
                <button class="error-close" onclick="this.parentElement.remove()">×</button>
            `;

            errorElement.style.cssText = `
                background-color: #f8d7da;
                color: #721c24;
                border: 1px solid #f5c6cb;
                border-radius: 4px;
                padding: 12px 15px;
                margin-bottom: 15px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                font-size: 14px;
                animation: fadeIn 0.3s ease-in;
            `;

            $container.parentElement.insertBefore(errorElement, $container);
        }

        const _clearErrorMessage = () => {
            const existingError = document.querySelector('.adyen-payment-error');
            if (existingError) {
                existingError.remove();
            }
        }

        const _onSubmitHandler = (e) => {
            if ($container.classList.contains('hidden')) {
                return;
            }

            e.preventDefault();
            e.stopPropagation();
        };

        const submitHandler = (state, dropin, url, actions) => {
            _clearErrorMessage();

            if (state.data.paymentMethod.type === 'ratepay' || state.data.paymentMethod.type === 'ratepay_directdebit') {
                if (!ratepayFingerprintState.fingerprint) {
                    _showErrorMessage('Device verification failed. Please refresh the page and try again.');
                    actions.reject();
                    return Promise.resolve();
                }

                state.data.deviceFingerprint = ratepayFingerprintState.fingerprint;
            }

            const options = {
                method: 'POST',
                body: JSON.stringify(state.data),
                headers: {
                    'Content-Type': 'application/json'
                }
            }

            _toggleLoader(true);

            return fetch(url, options)
                .then((response) => {
                    if (response.status >= 400 && response.status < 600){
                        return response.json().then(errorData => Promise.reject(errorData));
                    }

                    return response.json();
                })
                .then(data => {
                    _toggleLoader(false);

                    if (data.action) {
                        dropin.handleAction(data.action);
                    } else if (data.redirect) {
                        window.location.replace(data.redirect);
                    }

                    return data;
                })
                .catch(error => {
                    _toggleLoader(false);

                    if (error && error.error === true) {
                        _showErrorMessage(error.message);
                        actions.reject(error.message);
                    } else {
                        _showErrorMessage('Payment processing failed. Please try again.');
                        actions.reject();
                    }

                    if (dropin && typeof dropin.setStatus === 'function') {
                        setTimeout(() => {
                            dropin.setStatus('ready');
                        }, 100);
                    }

                    return undefined;
                });
        };

        const clearRatepayFingerprint = () => {
            // Only clear the current checkout's fingerprint, not all fingerprints
            if (ratepayFingerprintState.checkoutId) {
                try {
                    const existing = localStorage.getItem(RATEPAY_STORAGE_KEY);
                    if (existing) {
                        const fingerprintsMap = JSON.parse(existing);
                        delete fingerprintsMap[ratepayFingerprintState.checkoutId];

                        // If no fingerprints left, remove the key entirely
                        if (Object.keys(fingerprintsMap).length === 0) {
                            localStorage.removeItem(RATEPAY_STORAGE_KEY);
                        } else {
                            localStorage.setItem(RATEPAY_STORAGE_KEY, JSON.stringify(fingerprintsMap));
                        }
                    }
                } catch (e) {
                    // If parsing fails, just remove everything
                    localStorage.removeItem(RATEPAY_STORAGE_KEY);
                }
            }

            ratepayFingerprintState = {
                isGenerating: false,
                isReady: false,
                fingerprint: null,
                checkoutId: null
            };
        };

        const injectOnSubmitHandler = () => {

            if (!$form) {
                return;
            }

            const $buttons = $form.querySelectorAll('[type=submit]');

            $form.addEventListener('submit', _onSubmitHandler, true);

            $buttons.forEach(($btn) => {
                $btn.addEventListener('click', _onSubmitHandler, true);
            });
        };

        const disableStoredPaymentMethodHandler = (storedPaymentMethod, resolve, reject) => {
            const options = {
                method: 'DELETE'
            };

            let url = configuration.path.deleteToken.replace('_REFERENCE_', storedPaymentMethod);

            fetch(url, options)
                .then(resolve)
                .catch(reject)
            ;
        };

        const initRatepayFingerprint = () => {
            if (!configuration.ratepay || !configuration.ratepay.snippetId || !configuration.checkoutId || !configuration.buyerId) {
                return;
            }

            const { snippetId, dfpSessionId } = configuration.ratepay;
            const currentCheckoutId = configuration.checkoutId;
            const buyerId = configuration.buyerId;
            const deviceId = generateDeviceId();

            const buyerDeviceKey = `${buyerId}_${deviceId}`;

            const storedBuyerDeviceData = localStorage.getItem(RATEPAY_BUYER_DEVICE_KEY);
            let buyerDeviceFingerprints = {};

            if (storedBuyerDeviceData) {
                try {
                    buyerDeviceFingerprints = JSON.parse(storedBuyerDeviceData);
                } catch (e) {
                    buyerDeviceFingerprints = {};
                }
            }

            const existingFingerprint = buyerDeviceFingerprints[buyerDeviceKey];
            if (existingFingerprint && existingFingerprint.fingerprint) {
                ratepayFingerprintState.fingerprint = existingFingerprint.fingerprint;
                ratepayFingerprintState.checkoutId = currentCheckoutId;

                const allStoredFingerprints = localStorage.getItem(RATEPAY_STORAGE_KEY);
                let fingerprintsMap = {};
                if (allStoredFingerprints) {
                    try {
                        fingerprintsMap = JSON.parse(allStoredFingerprints);
                    } catch (e) {
                        fingerprintsMap = {};
                    }
                }
                fingerprintsMap[currentCheckoutId] = {
                    fingerprint: existingFingerprint.fingerprint,
                    timestamp: Date.now(),
                    buyerDeviceKey: buyerDeviceKey
                };
                localStorage.setItem(RATEPAY_STORAGE_KEY, JSON.stringify(fingerprintsMap));

                return;
            }

            ratepayFingerprintState.checkoutId = currentCheckoutId;

            const diScript = document.createElement('script');
            diScript.setAttribute('language', 'JavaScript');
            diScript.innerHTML = `var di = {t:'${dfpSessionId}', v:'${snippetId}', l:'Checkout'};`;
            document.getElementsByTagName('body')[0].appendChild(diScript);

            const script = document.createElement('script');
            script.type = 'text/javascript';
            script.src = `https://d.ratepay.com/${snippetId}/di.js`;
            document.getElementsByTagName('body')[0].appendChild(script);

            const noscript = document.createElement('noscript');
            const stylesheet = document.createElement('link');
            stylesheet.rel = 'stylesheet';
            stylesheet.type = 'text/css';
            stylesheet.href = `https://d.ratepay.com/di.css?t=${dfpSessionId}&v=${snippetId}&l=Checkout`;
            noscript.appendChild(stylesheet);
            document.getElementsByTagName('body')[0].appendChild(noscript);
        };

        const init = async () => {
            injectOnSubmitHandler();
            initRatepayFingerprint();

            return await AdyenCheckout({
                paymentMethodsResponse: configuration.paymentMethods,
                clientKey: configuration.clientKey,
                locale: configuration.locale,
                environment: configuration.environment,
                countryCode: configuration.billingAddress.countryCode,

                onSubmit: (state, dropin, actions) => {
                    submitHandler(state, dropin, configuration.path.payments, actions);
                },
                onAdditionalDetails: (state, dropin, actions) => {
                    submitHandler(state, dropin, configuration.path.paymentDetails, actions);
                },
                onPaymentCompleted: (result, component) => {
                    _toggleLoader(false);
                    clearRatepayFingerprint();
                    console.info(result, component);
                },
                onPaymentFailed: (result, component) => {
                    _toggleLoader(false);
                    clearRatepayFingerprint();
                    console.error('Payment failed:', result);
                },
                onError: (error, component) => {
                    _toggleLoader(false);
                    clearRatepayFingerprint();
                    console.error(error.name, error.message, error.stack, component);
                }
            });
        };

        configuration = await _loadConfiguration($container.attributes['data-config-url'].value);
        checkout = await init();

        const oneyConfiguration = {
            visibility: {
                personalDetails: 'hidden',
                billingAddress: 'hidden',
                deliveryAddress: 'hidden',
            },
        }

        const dropin = new Dropin(checkout, {
            paymentMethodsConfiguration: {
                card: {
                    hasHolderName: true,
                    holderNameRequired: true,
                    enableStoreDetails: configuration.enableStoreDetails,
                },
                paypal: {
                    environment: configuration.environment,
                    countryCode: configuration.billingAddress.countryCode,
                    amount: {
                        currency: configuration.amount.currency,
                        value: configuration.amount.value
                    }
                },
                applepay: {
                    countryCode: configuration.billingAddress.countryCode,
                    amount: {
                        currency: configuration.amount.currency,
                        value: configuration.amount.value
                    }
                },
                facilypay_3x: oneyConfiguration,
                facilypay_4x: oneyConfiguration,
                facilypay_6x: oneyConfiguration,
                facilypay_10x: oneyConfiguration,
                facilypay_12x: oneyConfiguration,
                ratepay: {
                    visibility: {
                        personalDetails: 'editable',
                        billingAddress: 'hidden',
                        deliveryAddress: 'hidden',
                    }
                }
            },
            showRemovePaymentMethodButton: true,
            onDisableStoredPaymentMethod: disableStoredPaymentMethodHandler
        });

        dropin.mount($container);
    };

    document.addEventListener('DOMContentLoaded', (e) => {
        document.querySelectorAll('.dropin-container').forEach(instantiate);
    })
})();
