const maskSelector = 'input[data-phone-mask="ru"]';

const onlyDigits = (value) => {
    return (value ?? '').replace(/\D+/g, '');
};

const normalizeRuDigits = (value) => {
    let digits = onlyDigits(value);

    if (digits.length === 0) {
        return '';
    }

    if (digits.length > 11) {
        digits = digits.slice(0, 11);
    }

    if (digits.length === 10) {
        return `7${digits}`;
    }

    if (digits.length === 11 && digits.startsWith('8')) {
        return `7${digits.slice(1)}`;
    }

    if (!digits.startsWith('7')) {
        digits = `7${digits.slice(0, 10)}`;
    }

    return digits.slice(0, 11);
};

const formatRuPhone = (value) => {
    const digits = normalizeRuDigits(value);

    if (digits === '') {
        return '';
    }

    const country = '+7';
    const area = digits.slice(1, 4);
    const prefix = digits.slice(4, 7);
    const blockOne = digits.slice(7, 9);
    const blockTwo = digits.slice(9, 11);

    if (digits.length <= 1) {
        return country;
    }

    if (digits.length <= 4) {
        return `${country} (${area}`;
    }

    if (digits.length <= 7) {
        return `${country} (${area}) ${prefix}`;
    }

    if (digits.length <= 9) {
        return `${country} (${area}) ${prefix}-${blockOne}`;
    }

    return `${country} (${area}) ${prefix}-${blockOne}-${blockTwo}`;
};

const applyMask = (element) => {
    if (!(element instanceof HTMLInputElement)) {
        return;
    }

    const formatted = formatRuPhone(element.value);

    if (element.value !== formatted) {
        element.value = formatted;
    }
};

const handleEvent = (event) => {
    if (!(event.target instanceof HTMLInputElement)) {
        return;
    }

    if (!event.target.matches(maskSelector)) {
        return;
    }

    applyMask(event.target);
};

const hydrateExistingInputs = () => {
    document.querySelectorAll(maskSelector).forEach((element) => {
        applyMask(element);
    });
};

let listenersAttached = false;
let observerAttached = false;

export const initRuPhoneMask = () => {
    if (typeof document === 'undefined') {
        return;
    }

    if (!listenersAttached) {
        document.addEventListener('input', handleEvent, true);
        document.addEventListener('focus', handleEvent, true);
        document.addEventListener('blur', handleEvent, true);
        listenersAttached = true;
    }

    if (!observerAttached && document.body) {
        const observer = new MutationObserver(() => hydrateExistingInputs());
        observer.observe(document.body, { childList: true, subtree: true });
        observerAttached = true;
    }

    hydrateExistingInputs();
};
