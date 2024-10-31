<script>
    function findPriceSelector()
    {
        var priceSelectors = <?php echo json_encode($priceSelector);?>;
        return priceSelectors.find(function(candidateSelector) {
            var priceDOM = document.querySelector(candidateSelector);
            return (priceDOM != null );
        });
    }

    function findQuantitySelector()
    {
        var quantitySelectors = <?php echo json_encode($quantitySelector);?>;
        return quantitySelectors.find(function(candidateSelector) {
            var priceDOM = document.querySelector(candidateSelector);
            return (priceDOM != null );
        });
    }

    function finishInterval() {
        clearInterval(window.loadingSimulator);
        return true;
    }

    function checkSimulatorContent() {
        var simulatorLoaded = false;
        var pmtDiv = document.getElementsByClassName("PmtSimulator");
        if(pmtDiv.length > 0) {
            var pmtElement = pmtDiv[0];
            if(pmtElement.innerHTML != '' )
            {
                simulatorLoaded = true;
            }
        }

        return simulatorLoaded;
    }

    function checkAttempts() {
        window.attempts = window.attempts + 1;
        return (window.attempts > 4)
    }

    //Main function
    function loadSimulator()
    {
        if (typeof pmtSDK == 'undefined')
        {
            return false;
        }

        if (checkAttempts() || checkSimulatorContent())
        {
            return finishInterval();
        }

        var price = '<?php echo $total;?>';
        var positionSelector = '<?php echo $positionSelector;?>';
        if (positionSelector === 'default') {
            positionSelector = '.PmtSimulator';
        }

        var priceSelector = findPriceSelector();

        var quantitySelector = findQuantitySelector();

        if (typeof pmtSDK != 'undefined') {
            window.WCSimulatorId = pmtSDK.simulator.init({
                publicKey: '<?php echo $public_key; ?>',
                type: <?php echo $simulator_type; ?>,
                selector: positionSelector,
                itemQuantitySelector: quantitySelector,
                itemAmountSelector: priceSelector
            });
            return false;
        }
    }

    window.attempts = 0;
    window.loadingSimulator = setInterval(function () {
        loadSimulator();
    }, 2000);
</script>
<div class="PmtSimulator"></div>
