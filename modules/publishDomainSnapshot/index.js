;(function () {
  var publishDomainSnapshot

  var ethChainIdMap = {
    'mainnet': '0x1',
    'sepolia': '0xaa36a7',
    'holesky': '0x4268',
  }

  function storeWitnessTx(txhash, host, witnessEventID, ownAddress, witnessNetwork) {
    console.log({txhash: txhash});
    const payload = {
      witness_event_id: witnessEventID,
      account_address: ownAddress,
      transaction_hash: txhash,
      witness_network: witnessNetwork
    }
    const cmd =
      host + '/rest.php/data_accounting/write/store_witness_tx';
    console.log(cmd);
    fetch(
      cmd,
      { method: 'POST',
        cache: 'no-cache',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload)
      })
    .then((out) => {
      console.log("After DB operation");
      console.log(out);
      // Refresh the page after success
      location.reload();
    })
  }

  publishDomainSnapshot = {
    init: function () {
      $(".publish-domain-snapshot").click(
        function() {
          function formatHash(hash) {
            // Format verification hash to be fed into the smart contract
            const midpoint = hash.length / 2
            const first = hash.slice(0, midpoint)
            const second = hash.slice(midpoint)
            return '[0x' + first + ',0x' + second + ']'
          }
          if (window.ethereum) {
            if (window.ethereum.isConnected() && window.ethereum.selectedAddress) {
              const witnessEventID = $(this).attr('id')
              const host = window.location.protocol + '//' + window.location.hostname + ':' + window.location.port
              fetch(
                host + '/rest.php/data_accounting/get_witness_data/' + witnessEventID,
                { method: 'GET' })
                .then((resp) => {
                  if (!resp.ok) {
                    resp.text().then(parsed => alert(parsed)
                    )
                    return
                  }
                  resp.json().then(async parsed => {
                    console.log(parsed)
                    const ownAddress = window.ethereum.selectedAddress
                    const chainId = await window.ethereum.request({ method: 'eth_chainId' })
                    const serverChainId = ethChainIdMap[parsed.witness_network]
                    if (serverChainId !== chainId) {
                      console.log(serverChainId, chainId)
                      // Switch network if the Wallet network does not match DA
                      // server network.
                      await window.ethereum.request({
                        method: 'wallet_switchEthereumChain',
                        params: [{
                          chainId: serverChainId,
                        }],
                      })
                    }
                    const params = [
                      {
                        from: ownAddress,
                        to: parsed.smart_contract_address,
                        // gas and gasPrice are optional values which are
                        // automatically set by MetaMask.
                        // gas: '0x7cc0', // 30400
                        // gasPrice: '0x328400000',
                        data: '0x9cef4ea1' + parsed.witness_event_verification_hash,
                      },
                    ]
                    window.ethereum
                    .request({
                      method: 'eth_sendTransaction',
                      params: params,
                    })
                    .then(txhash => storeWitnessTx(txhash, host, witnessEventID, ownAddress, parsed.witness_network))
                  })
                })
                .catch(error => {
                  alert(error)
                })
            } else {
              window.ethereum.request({ method: 'eth_requestAccounts' })
            }
          }
        }
      )
    },
  }

  module.exports = publishDomainSnapshot

  mw.publishDomainSnapshot = publishDomainSnapshot
})()
