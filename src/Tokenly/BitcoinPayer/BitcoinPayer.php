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

    public function __construct($bitcoind_client, $insight_client) {
        $this->bitcoind_client = $bitcoind_client;
        $this->insight_client  = $insight_client;
    }

    ////////////////////////////////////////////////////////////////////////

    // returns an array of the $transaction_id and $balance sent (as float)
    public function sweepBTC($source_address, $destination_address, $private_key, $float_fee) {
        // get the current balance
        $utxos = $this->getUnspentOutputs($source_address);
        $float_balance = $this->sumUnspentOutputs($utxos);

        // compose destinations array with the entire amount
        $destinations = [
            $destination_address => $float_balance - $float_fee,
        ];

        // send the transaction
        $transaction_id = $this->doTransaction($private_key, $utxos, $destinations);

        // calculate amount sent and return
        $float_balance_sent = $float_balance - $float_fee;
        return [$transaction_id, $float_balance_sent];
    }


    // returns the transaction id
    public function sendBTC($source_address, $destination_address, $float_amount, $private_key, $float_fee) {
        $float_amount = round($float_amount, 8);

        // get the current balance
        $utxos = $this->getUnspentOutputs($source_address);

        // get just enough utxos to sum to amount
        $utxos = $this->filterUnspentOutputsToSatisfyAmount($utxos, $float_amount + $float_fee);

        // get the total balance of the utxos we are sending
        $float_balance = $this->sumUnspentOutputs($utxos);

        // calculate change amount
        $change_amount = round($float_balance - $float_amount - $float_fee, 8);
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

        // send the transaction
        $transaction_id = $this->doTransaction($private_key, $utxos, $destinations);

        return $transaction_id;
    }


    public function getBalance($source_address) {
        $utxos = $this->getUnspentOutputs($source_address);
        $float_balance = $this->sumUnspentOutputs($utxos);
        return $float_balance;
    }

    ////////////////////////////////////////////////////////////////////////

    protected function doTransaction($private_key, $utxos, $destinations) {
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

        // send the transaction
        $transaction_id = $this->bitcoind_client->sendrawtransaction($signed_tx['hex']);

        return $transaction_id;
    }


    // returns an array of utxos from insight (filtered through bitcoind)
    protected function getUnspentOutputs($address) {
        $utxos = $this->insight_client->getUnspentTransactions($address);

        // because of a bug in insight, we need to filter out utxos that don't exist
        $utxos = $this->filterBadUTXOs($utxos);

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

}

