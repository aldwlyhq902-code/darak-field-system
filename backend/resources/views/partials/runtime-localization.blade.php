@if(app()->isLocale('en'))
<script nonce="{{ $cspNonce }}">
(() => {
    const dictionary = @json(trans('ui'));
    const phrases = Object.entries(dictionary)
        .filter(([source]) => source.length >= 4)
        .sort((a, b) => b[0].length - a[0].length);
    const translate = value => {
        const trimmed = value.trim();
        if (!trimmed) return value;
        if (dictionary[trimmed]) return value.replace(trimmed, dictionary[trimmed]);
        let translated = value;
        for (const [source, target] of phrases) translated = translated.split(source).join(target);
        return translated;
    };
    const translateElement = element => {
        for (const attribute of ['aria-label', 'alt', 'placeholder', 'title']) {
            if (element.hasAttribute?.(attribute)) {
                const current = element.getAttribute(attribute);
                const translated = translate(current);
                if (translated !== current) element.setAttribute(attribute, translated);
            }
        }
    };
    const translateTree = root => {
        if (root.nodeType === Node.TEXT_NODE) {
            if (!root.parentElement?.closest('script,style,noscript,[data-no-translate]')) {
                const translated = translate(root.nodeValue);
                if (translated !== root.nodeValue) root.nodeValue = translated;
            }
            return;
        }
        if (root.nodeType !== Node.ELEMENT_NODE && root.nodeType !== Node.DOCUMENT_NODE) return;
        if (root.nodeType === Node.ELEMENT_NODE) translateElement(root);
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT);
        while (walker.nextNode()) {
            if (walker.currentNode.nodeType === Node.TEXT_NODE) {
                if (!walker.currentNode.parentElement?.closest('script,style,noscript,[data-no-translate]')) {
                    const translated = translate(walker.currentNode.nodeValue);
                    if (translated !== walker.currentNode.nodeValue) walker.currentNode.nodeValue = translated;
                }
            } else translateElement(walker.currentNode);
        }
    };
    translateTree(document.body);
    document.title = translate(document.title);
    new MutationObserver(records => records.forEach(record => {
        if (record.type === 'characterData') translateTree(record.target);
        record.addedNodes?.forEach(translateTree);
        if (record.type === 'attributes') translateElement(record.target);
    })).observe(document.body, {subtree:true, childList:true, characterData:true, attributes:true, attributeFilter:['aria-label','alt','placeholder','title']});
})();
</script>
@endif
