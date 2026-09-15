
document.addEventListener("DOMContentLoaded", function () {

    function toggleEncaisseBox() {
        const selected = document.querySelector("input[name='checkout[payment][id]']:checked");
        const box = document.querySelector(".encaisse-box");
        if (!selected || !box) {
            return;
        }
        const checkout = JSON.parse(selected.dataset.hkCheckout);
        box.style.display =
            checkout.type === "encaisse"
                ? "block"
                : "none";
    }

    function highlightSelected(radio) {
        document.querySelectorAll(".encaisse-card").forEach(function (c) {
            c.classList.remove("selected");
        });
        if (radio && radio.checked) {
            radio.nextElementSibling.classList.add("selected");
        }
    }

    function saveChoice(value) {
        // console.log("CLICK partenaire =", value);
        fetch("index.php?option=com_ajax&plugin=encaisse&format=raw&group=hikashoppayment&method=saveChoice", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: "value=" + encodeURIComponent(value)
        })
            .then(res => res.text())
            .then(data => { console.log("AJAX RESPONSE =", data); })
            .catch(err => { console.error("AJAX ERROR =", err); });
    }

    function bindEvents() {
        // Sélection du moyen de paiement HikaShop
        document.querySelectorAll("input[name='checkout[payment][id]']").forEach(function (radio) {
            radio.removeEventListener("change", toggleEncaisseBox);
            radio.addEventListener("change", toggleEncaisseBox);
        });
        // Sélection d'un partenaire Encaisse
        document.querySelectorAll(".encaisse-item input[type=radio]").forEach(function (radio) {
            radio.removeEventListener("change", radio._encaisseHandler || (() => { }));
            radio._encaisseHandler = function () {
                highlightSelected(this);
                saveChoice(this.value);
            };
            radio.addEventListener("change", radio._encaisseHandler);
        });
    }
    // Init
    toggleEncaisseBox();
    bindEvents();
    /**
     * IMPORTANT HikaShop recharge souvent le DOM en AJAX
     * On observe les changements pour réattacher les events
     */
    const observer = new MutationObserver(function () {
        bindEvents();
        toggleEncaisseBox();
    });
    observer.observe(document.body, {
        childList: true,
        subtree: true
    });

});