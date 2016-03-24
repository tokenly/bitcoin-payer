<?php

namespace Tokenly\BitcoinPayer;

use Exception;
use Illuminate\Database\QueryException;
use Tokenly\BitcoinPayer\Exception\PaymentException;

/*
* BitcoinPayer
*/
class BitcoinPayer
{
    const HIGH_FEE            = 0.01;
    const MINIMUM_CHANGE_SIZE = 0.00005000;

    const CACHE_CONFIRMATIONS = 6;

    public function __construct($bitcoind_client, $bitcoind_rpc_client, $utxo_cache_table_provider=null) {
        $this->bitcoind_client           = $bitcoind_client;
        $this->bitcoind_rpc_client       = $bitcoind_rpc_client;
        $this->utxo_cache_table_provider = $utxo_cache_table_provider;
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
        // \Illuminate\Support\Facades\Log::debug("\$source_address=$source_address \$float_balance=$float_balance \$float_amount=$float_amount \$float_fee=$float_fee \$change_amount=$change_amount");
        if ($change_amount < 0) { throw new PaymentException("Address did not have enough funds for this transaction", 1); }

        // compose destinations array with the entire amount
        $destinations = [
            $destination_address => $float_amount,
        ];
        if ($change_amount > 0) {
            if ($change_amount >= self::MINIMUM_CHANGE_SIZE) {
                $destinations[$source_address] = $change_amount;
            } else {
                // very small change transactions are not allowed
                //   set change to 0 and increase the fee
                $change_amount = 0;
                // EventLog::debug('payer.feeIncreased', [
                //     'msg'             => 'Change was too small',
                //     'requestedChange' => CurrencyUtil::valueToSatoshis($change_amount),
                //     'oldFeeSat'       => CurrencyUtil::valueToSatoshis($float_fee),
                //     'newFeeSat'       => CurrencyUtil::valueToSatoshis($float_balance - $float_amount - $change_amount),
                // ]);
            }
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


    // returns an array of utxos from bitcoind
    protected function getUnspentOutputs($address) {
        // use bitcoind to get UTXOs
        $utxos = $this->getUnspentOutputsFromBitcoind($address);

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

        // get all txos
        \Illuminate\Support\Facades\Log::debug("searchrawtransactions begin ($address)");
        $rpc_result = $this->bitcoind_rpc_client->execute('searchrawtransactions', [$address,0,0,9999999]);
        \Illuminate\Support\Facades\Log::debug("searchrawtransactions end ($address)");

        $txos = [];
        $raw_txos = json_decode(json_encode($rpc_result->result), true);
        $raw_txos_count = count($raw_txos);
        foreach ($raw_txos as $offset => $raw_txo_hex) {
            // for testing
            if (is_array($raw_txo_hex)) { $txos[] = $raw_txo_hex; continue; }

            // hash = sha256(sha256(data).digest()).digest()
            $big_endian_hex = bin2hex(hash('sha256', hash('sha256', hex2bin($raw_txo_hex), true), true));
            $txid = implode('', array_reverse(str_split($big_endian_hex, 2)));

            // try to load from the database table
            $decoded_tx = null;
            if ($this->utxo_cache_table_provider) {
                $record = $this->utxo_cache_table_provider->__invoke()->where('txid', '=', $txid)->first();
                if ($record) {
                    $decoded_tx = json_decode($record->transaction, true);
                    $txos[] = $decoded_tx;
                    continue;
                }
            }

            // load the transaction from bitcoind
            \Illuminate\Support\Facades\Log::debug(($offset+1)." of $raw_txos_count for $address");
            $decoded_tx = json_decode(json_encode($this->bitcoind_rpc_client->execute('getrawtransaction', [$txid, 1])->result), true);
            $txos[] = $decoded_tx;

            // insert into cache
            if ($this->utxo_cache_table_provider) {
                try {
                    if ($decoded_tx['confirmations'] >= self::CACHE_CONFIRMATIONS) {
                        $this->utxo_cache_table_provider->__invoke()->insert([
                            'txid'        => $txid,
                            'transaction' => json_encode($decoded_tx),
                            'last_update' => date("Y-m-d H:i:s"),
                        ]);
                    }
                } catch (QueryException $e) {
                    if ($e->errorInfo[0] == 23000) {
                        // ignore duplicates
                    } else {
                        throw $e;
                    }
                }
            }
        }

        $spent_txos_map = [];
        foreach($txos as $txo) {
            foreach($txo['vin'] as $vin) {
                if (isset($vin['txid']) AND isset($vin['vout'])) {
                    $spent_txos_map[$vin['txid'].':'.$vin['vout']] = true;
                }
            }
        }

        // find unspent txos
        $unspent_txos = [];
        foreach($txos as $txo) {
            foreach($txo['vout'] as $vout) {
                if (isset($vout['scriptPubKey']) AND isset($vout['scriptPubKey']['type']) AND $vout['scriptPubKey']['type'] == 'pubkeyhash') {
                    if (isset($vout['scriptPubKey']['addresses']) AND in_array($address, $vout['scriptPubKey']['addresses'])) {
                        $utxo_key = $txo['txid'].':'.$vout['n'];

                        if (!isset($spent_txos_map[$utxo_key])) {
                            if (isset($unspent_txos[$utxo_key])) {
                                // ignore 0 conf utxos if there is a confirmed tx to replace it
                                if ($txo['confirmations'] < self::CACHE_CONFIRMATIONS AND $txo['confirmations'] < $unspent_txos[$utxo_key]['confirmations']) {
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
            'script'        => $bitcoind_vout['scriptPubKey']['hex'],
            'amount'        => $bitcoind_vout['value'],
            'confirmations' => $bitcoind_txo['confirmations'],
            'confirmed'     => ($bitcoind_txo['confirmations'] > 0),
        ];
    }


}

