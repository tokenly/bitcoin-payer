<?php

namespace Tokenly\BitcoinPayer;

use Exception;
use Tokenly\BitcoinPayer\Exception\PaymentException;

/*
* BitcoinPayer
*/
class BitcoinPayer
{
    const HIGH_FEE = 0.01;

    public function __construct($bitcoind_client, $insight_client, $bitcoind_rpc_client) {
        $this->bitcoind_client     = $bitcoind_client;
        $this->insight_client      = $insight_client;
        $this->bitcoind_rpc_client = $bitcoind_rpc_client;
    }

    ////////////////////////////////////////////////////////////////////////

    // returns an array of the $transaction_id and $balance sent (as float)
    public function sweepBTC($source_address, $destination_address, $private_key, $float_fee) {
        // compose the transaction
        list($signed_transaction_hex, $float_balance) = $this->buildSignedTransactionAndBalanceToSweepBTC($source_address, $destination_address, $private_key, $float_fee, $utxos);

        // send the transaction
        $transaction_id = $this->sendSignedTransaction($signed_transaction_hex);

        // return the transaction id and the calculated amount sent
        return [$transaction_id, $float_balance];
    }

    public function buildSignedTransactionAndBalanceToSweepBTC($source_address, $destination_address, $private_key, $float_fee) {
        // get the current balance
        $utxos = $this->getUnspentOutputs($source_address);
        $float_balance = $this->sumUnspentOutputs($utxos);

        // compose destinations array with the entire amount
        $destinations = [
            $destination_address => $float_balance - $float_fee,
        ];

        $signed_transaction_hex = $this->createAndSignTransaction($private_key, $utxos, $destinations);
        return [$signed_transaction_hex, $float_balance];
    }


    // returns the transaction id
    public function sendBTC($source_address, $destination_address, $float_amount, $private_key, $float_fee) {
        list($utxos, $destinations) = $this->buildUTXOsAndDestinations($source_address, $destination_address, $float_amount, $float_fee);

        // create the transaction
        $signed_transaction_hex = $this->createAndSignTransaction($private_key, $utxos, $destinations);

        // send the transaction
        $transaction_id = $this->sendSignedTransaction($signed_transaction_hex);

        return $transaction_id;
    }

    public function buildSignedTransactionHexToSendBTC($source_address, $destination_address, $float_amount, $private_key, $float_fee) {
        list($utxos, $destinations) = $this->buildUTXOsAndDestinations($source_address, $destination_address, $float_amount, $float_fee);

        // compose the transaction
        $signed_transaction_hex = $this->createAndSignTransaction($private_key, $utxos, $destinations);

        return $signed_transaction_hex;
    }

    public function sendSignedTransaction($signed_transaction_hex) {
        $transaction_id = $this->bitcoind_client->sendrawtransaction($signed_transaction_hex);
        return $transaction_id;
    }


    public function getBalance($source_address) {
        $utxos = $this->getUnspentOutputs($source_address);
        $float_balance = $this->sumUnspentOutputs($utxos);
        return $float_balance;
    }

    public function getAllUTXOs($address) {
        $utxos = $this->getUnspentOutputs($address);
        return $utxos;
    }

    ////////////////////////////////////////////////////////////////////////

    protected function buildUTXOsAndDestinations($source_address, $destination_address, $float_amount, $float_fee) {
        $float_amount = round($float_amount, 8);

        // don't send 0
        if ($float_amount <= 0) { throw new PaymentException("Cannot send an amount of 0 or less.", 1); }

        // get the current balance
        $utxos = $this->getUnspentOutputs($source_address);

        // get just enough utxos to sum to amount
        $utxos = $this->filterUnspentOutputsToSatisfyAmount($utxos, $float_amount + $float_fee);

        // get the total balance of the utxos we are sending
        $float_balance = $this->sumUnspentOutputs($utxos);

        // calculate change amount
        $change_amount = round($float_balance - $float_amount - $float_fee, 8);
        \Illuminate\Support\Facades\Log::debug("\$source_address=$source_address \$float_balance=$float_balance \$float_amount=$float_amount \$float_fee=$float_fee \$change_amount=$change_amount");
        if ($change_amount < 0) { throw new PaymentException("Address did not have enough funds for this transaction", 1); }

        // compose destinations array with the entire amount
        $destinations = [
            $destination_address => $float_amount,
        ];
        if ($change_amount > 0) {
            $destinations[$source_address] = $change_amount;
        }

        // sanity check
        if (($float_balance - $float_amount - $change_amount) >= self::HIGH_FEE) {
            if ($float_fee < self::HIGH_FEE) { throw new PaymentException("Calculated fee was too high."); }
        }

        return [$utxos, $destinations];
    }

    protected function createAndSignTransaction($private_key, $utxos, $destinations) {
        // construct a transaction with all the unspent outputs
        $inputs = [];
        foreach($utxos as $utxo) {
            $inputs[] = ['txid' => $utxo['txid'], 'vout' => $utxo['vout']];
        }

        // create the raw transaction
        $raw_tx = $this->bitcoind_client->createrawtransaction($inputs, $destinations);

        // sign the raw transaction
        $signed_tx = (array)$this->bitcoind_client->signrawtransaction($raw_tx, [], [$private_key]);
        if (!$signed_tx['complete']) { throw new PaymentException("Failed to sign transaction with the given key", 1); }

        return $signed_tx['hex'];
    }


    // returns an array of utxos from insight (filtered through bitcoind)
    protected function getUnspentOutputs($address) {
        // insight has become too unreliable
        // $utxos = $this->insight_client->getUnspentTransactions($address);

        // use bitcoind instead
        $utxos = $this->getUnspentOutputsFromBitcoind($address);

        // // because of a bug in insight, we need to filter out utxos that don't exist
        // $utxos = $this->filterBadUTXOs($utxos);

        return $utxos;
    }

    // returns a float
    protected function sumUnspentOutputs($unspent_outputs) {
        $float_total = 0;
        foreach($unspent_outputs as $unspent_output) {
            $float_total += $unspent_output['amount'];

        }
        return $float_total;
    }

    protected function filterBadUTXOs($utxos) {
        $utxos_out = [];

        foreach($utxos as $utxo) {
            try {
                $txid = $utxo['txid'];
                $raw_transaction_string = $this->bitcoind_client->getrawtransaction($txid);
                if (strlen($raw_transaction_string)) {
                    $utxos_out[] = $utxo;
                }
            } catch (Exception $e) {
                if ($e->getCode() == -5) {
                    // -5: No information available about transaction
                    // skip this transaction
                    continue;
                }

                // some unknown error
                throw $e;
            }
        }

        return $utxos_out;
    }

    // try to get just enough UTXOs to cover the total amount
    //   will return an array of up to all the utxos passed
    protected function filterUnspentOutputsToSatisfyAmount($utxos, $total_amount_float) {
        $amount_sum = 0.0;
        $filtered_utxos = [];
        foreach($utxos as $utxo) {
            $filtered_utxos[] = $utxo;
            $amount_sum += $utxo['amount'];

            if ($amount_sum >= $total_amount_float) {
                break;
            }
        }

        return $filtered_utxos;
    }

    protected function getUnspentOutputsFromBitcoind($address) {
        $unspent_txos = [];

        // get all txos
        $rpc_result = $this->bitcoind_rpc_client->execute('searchrawtransactions', [$address,1,0,9999999]);
        $txos = json_decode(json_encode($rpc_result->result), true);

        $spent_txos_map = [];
        foreach($txos as $txo) {
            foreach($txo['vin'] as $vin) {
                $spent_txos_map[$vin['txid'].':'.$vin['vout']] = true;
            }
        }

        // find unspent txos
        foreach($txos as $txo) {
            foreach($txo['vout'] as $vout) {
                if (isset($vout['scriptPubKey']) AND isset($vout['scriptPubKey']['type']) AND $vout['scriptPubKey']['type'] == 'pubkeyhash') {
                    if (isset($vout['scriptPubKey']['addresses']) AND in_array($address, $vout['scriptPubKey']['addresses'])) {
                        $utxo_key = $txo['txid'].':'.$vout['n'];

                        if (!isset($spent_txos_map[$utxo_key])) {
                            if (isset($unspent_txos[$utxo_key])) {
                                // ignore 0 conf utxos if there is a confirmed tx to replace it
                                if ($txo['confirmations'] < $unspent_txos[$utxo_key]['confirmations']) {
                                    continue;
                                }
                            }
                            $unspent_txo = $this->normalizeBitcoindUTXO($vout, $txo);
                            $unspent_txos[$utxo_key] = $unspent_txo;
                        }
                    }
                }
            }
        }

        return $unspent_txos;
    }

    protected function normalizeBitcoindUTXO($bitcoind_vout, $bitcoind_txo) {
        return [
            'address'       => $bitcoind_vout['scriptPubKey']['addresses'][0],
            'txid'          => $bitcoind_txo['txid'],
            'vout'          => $bitcoind_vout['n'],
            'scriptPubKey'  => $bitcoind_vout['scriptPubKey']['hex'],
            'amount'        => $bitcoind_vout['value'],
            'confirmations' => $bitcoind_txo['confirmations'],
        ];
    }

/*

Insight $utxos: [
    {
        "address": "1EAPKbZRV4KNTBVFSNse4u7JiKWHNHUE2Y",
        "txid": "460de4975ebdb8bfde4baf7233ce401d0ac3c61171897c375c2976bb9ba402ba",
        "vout": 1,
        "ts": 1446680179,
        "scriptPubKey": "76a914906002db4dd7dd6f1a2a182c0037159348760fa388ac",
        "amount": 0.0006902,
        "confirmationsFromCache": false
    },
    {
        "address": "1EAPKbZRV4KNTBVFSNse4u7JiKWHNHUE2Y",
        "txid": "dca10e5fce50237b047bcbd21b9c28bef0e84d04e12bbf8dd9fd90c30c45eb2a",
        "vout": 0,
        "ts": 1445132794,
        "amount": 0.03661394,
        "confirmationsFromCache": false
    }
]



bitcoind vouts: [
    {
        "value": 0.001,
        "n": 0,
        "scriptPubKey": {
            "asm": "OP_DUP OP_HASH160 906002db4dd7dd6f1a2a182c0037159348760fa3 OP_EQUALVERIFY OP_CHECKSIG",
            "hex": "76a914906002db4dd7dd6f1a2a182c0037159348760fa388ac",
            "reqSigs": 1,
            "type": "pubkeyhash",
            "addresses": [
                "1EAPKbZRV4KNTBVFSNse4u7JiKWHNHUE2Y"
            ]
        }
    },
    {
        "value": 5.47e-5,
        "n": 0,
        "scriptPubKey": {
            "asm": "OP_DUP OP_HASH160 906002db4dd7dd6f1a2a182c0037159348760fa3 OP_EQUALVERIFY OP_CHECKSIG",
            "hex": "76a914906002db4dd7dd6f1a2a182c0037159348760fa388ac",
            "reqSigs": 1,
            "type": "pubkeyhash",
            "addresses": [
                "1EAPKbZRV4KNTBVFSNse4u7JiKWHNHUE2Y"
            ]
        }
    },


 */
}

