<?php

namespace LTBAuctioneer\Auctioneer\Payer;

use GuzzleHttp\Client as GuzzleClient;
use LTBAuctioneer\Debug\Debug;
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
        $unspent_outputs = $this->getUnspentOutputs($source_address);

        // sum the unspent outputs total
        $float_balance = $this->sumUnspentOutputs($unspent_outputs);

        // construct a transaction with all unspent outputs
        $inputs = [];
        foreach($unspent_outputs as $unspent_output) {
            $inputs[] = ['txid' => $unspent_output['tx'], 'vout' => $unspent_output['n']];
        }
        $outputs = [$destination_address => $float_balance - $float_fee];

        // create the raw transaction
        $raw_tx = $this->bitcoind_client->createrawtransaction($inputs, $outputs);

        // sign the raw transaction
        $signed_tx = (array)$this->bitcoind_client->signrawtransaction($raw_tx, [], [$private_key]);
        if (!$signed_tx['complete']) { throw new Exception("Failed to sign transaction with the given key", 1); }

        // send the transaction
        $transaction_id = $this->bitcoind_client->sendrawtransaction($signed_tx['hex']);

        // return the result
        $float_balance_sent = $float_balance - $float_fee;
        return [$transaction_id, $float_balance_sent];
    }

    ////////////////////////////////////////////////////////////////////////

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

