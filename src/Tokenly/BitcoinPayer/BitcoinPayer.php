<?php

namespace Tokenly\BitcoinPayer;

use GuzzleHttp\Client as GuzzleClient;
use Exception;

/*
* BitcoinPayer
*/
class BitcoinPayer
{

    public function __construct($bitcoind_client) {
        $this->bitcoind_client = $bitcoind_client;
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
        $float_balance = $this->sumUnspentOutputs($utxos);

        // calculate change amount
        $change_amount = round($float_balance - $float_amount - $float_fee, 8);
        if ($change_amount < 0) { throw new Exception("Address did not have enough funds for this transaction", 1); }

        // compose destinations array with the entire amount
        $destinations = [
            $destination_address => $float_amount,
        ];
        if ($change_amount > 0) {
            $destinations[$source_address] = $change_amount;
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
            $inputs[] = ['txid' => $utxo['tx'], 'vout' => $utxo['n']];
        }

        // create the raw transaction
        $raw_tx = $this->bitcoind_client->createrawtransaction($inputs, $destinations);

        // sign the raw transaction
        $signed_tx = (array)$this->bitcoind_client->signrawtransaction($raw_tx, [], [$private_key]);
        if (!$signed_tx['complete']) { throw new Exception("Failed to sign transaction with the given key", 1); }

        // send the transaction
        $transaction_id = $this->bitcoind_client->sendrawtransaction($signed_tx['hex']);

        return $transaction_id;
    }


    // returns a float
    protected function getUnspentOutputs($address) {
        // get all funds (use blockr)
        // http://btc.blockr.io/api/v1/address/unspent/1EuJjmRA2kMFRhjAee8G6aqCoFpFnNTJh4
        $client = new GuzzleClient(['base_url' => 'http://btc.blockr.io',]);
        $response = $client->get('/api/v1/address/unspent/'.$address);
        $json_data = $response->json();
        return $json_data['data']['unspent'];
    }

    protected function sumUnspentOutputs($unspent_outputs) {
        $float_total = 0;
        foreach($unspent_outputs as $unspent_output) {
            $float_total += $unspent_output['amount'];

        }
        return $float_total;
    }

}

