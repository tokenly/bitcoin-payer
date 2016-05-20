<?php

namespace Tokenly\BitcoinPayer;

use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tokenly\BitcoinPayer\Exception\PaymentException;
use Tokenly\CurrencyLib\CurrencyUtil;

/*
* BitcoinPayer
*/
class BitcoinPayer
{
    const HIGH_FEE            = 0.01;
    const MINIMUM_CHANGE_SIZE = 0.00005000;

    const CACHE_CONFIRMATIONS = 6;

    public function __construct($bitcoind_client, $bitcoind_rpc_client, $utxo_cache_table_provider) {
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

    // ------------------------------------------------------------------------
    
    protected function getUnspentOutputsFromBitcoind($address) {
        return DB::transaction(function() use ($address) {
            // clear everything from the cache with confirmations < 6
            $this->clearImmatureTransactions($address);

            // get all txos
            $rpc_result = $this->bitcoind_rpc_client->execute('searchrawtransactions', [$address,0,0,9999999]);
            $raw_txos = json_decode(json_encode($rpc_result->result), true);
            $raw_txos_count = count($raw_txos);
            foreach ($raw_txos as $offset => $raw_transacton_hex) {
                // \Illuminate\Support\Facades\Log::debug(($offset+1)." of $raw_txos_count for $address");

                if (is_array($raw_transacton_hex)) {
                    // if this was provided as an array (for testing), just use it
                    $decoded_tx = $raw_transacton_hex;
                    $this->updateUTXOCacheWithDecodedTransactionData($address, $decoded_tx);
                } else {
                    $this->updateUTXOCacheWithRawTransactionHex($address, $raw_transacton_hex);
                }
            }

            // get all the TXOs that are still unspent
            return $this->loadNormalizedUnspentUTXORecords($address);
        });
    }

    protected function updateUTXOCacheWithDecodedTransactionData($address, $decoded_tx) {
        // if this transaction is found in the database, don't add any outputs again
        $txid = $decoded_tx['txid'];
        $is_cached_transaction = $this->isCachedTransaction($address, $txid);
        // \Illuminate\Support\Facades\Log::debug("\$is_cached_transaction=".json_encode($is_cached_transaction, 192));

        if (!$is_cached_transaction) {
            // load this transaction from bitcoind and add each spent TXO (input) to the cache table
            $this->updateUTXOCacheDatabaseEntriesWithDecodedTransactionData($address, $decoded_tx);
        }
    }

    protected function updateUTXOCacheWithRawTransactionHex($address, $raw_transacton_hex) {
        // calculate the txid using the raw transaction hex
        $big_endian_hex = bin2hex(hash('sha256', hash('sha256', hex2bin($raw_transacton_hex), true), true));
        $txid = implode('', array_reverse(str_split($big_endian_hex, 2)));

        // if this transaction is found in the database, don't add any outputs again
        $is_cached_transaction = $this->isCachedTransaction($address, $txid);

        if (!$is_cached_transaction) {
            // load this transaction from bitcoind and add each spent TXO (input) to the cache table
            $decoded_tx = json_decode(json_encode($this->bitcoind_rpc_client->execute('getrawtransaction', [$txid, 1])->result), true);
            $this->updateUTXOCacheDatabaseEntriesWithDecodedTransactionData($address, $decoded_tx);
        }
    }

    protected function isCachedTransaction($address, $txid) {
        $cache_table = $this->utxo_cache_table_provider->__invoke();
        $found_cached_record = $cache_table
            ->where('address_reference', '=', $address)
            ->where('txid', '=', $txid)
            ->first(['txid']);
        return (!!$found_cached_record);
    }

    protected function clearImmatureTransactions($address) {
        $cache_table = $this->utxo_cache_table_provider->__invoke();
        $cache_table
            ->where('address_reference', '=', $address)
            ->where('confirmations', '<', self::CACHE_CONFIRMATIONS)
            ->delete();

        $cache_table = $this->utxo_cache_table_provider->__invoke();
        $cache_table
            ->where('address_reference', '=', $address)
            ->where('spent_confirmations', '<', self::CACHE_CONFIRMATIONS)
            ->delete();
    }

    protected function loadNormalizedUnspentUTXORecords($address) {
        $normalized_unspent_txos = [];

        $cache_table = $this->utxo_cache_table_provider->__invoke();
        $unspent_txos = $cache_table
            ->where('address_reference', '=', $address)
            ->where('destination_address', '=', $address)
            ->where('spent', '=', 0)
            ->get();
        foreach($unspent_txos as $unspent_txo_record) {
            $normalized_unspent_txos[] = $this->normalizeBitcoindUTXORecord($unspent_txo_record);
        }

        return $normalized_unspent_txos;
    }

    protected function updateUTXOCacheDatabaseEntriesWithDecodedTransactionData($address, $decoded_tx) {
        // process all vins
        $this->insertTXInputsIntoCacheTable($address, $decoded_tx['txid'], $decoded_tx['vin'], $decoded_tx['confirmations']);

        // process all vouts
        $this->insertTXOutputsIntoCacheTable($address, $decoded_tx['txid'], $decoded_tx['vout'], $decoded_tx['confirmations']);
    }
 
    protected function insertTXInputsIntoCacheTable($address_reference, $txid, $vins, $confirmations) {
        foreach($vins as $vin) {
            if (isset($vin['txid']) AND isset($vin['vout'])) {
                // this is a previous txid:n (TXO) that is spent in this transaction
                $insert_vars = [
                    'address_reference'   => $address_reference,
                    'txid'                => $vin['txid'],
                    'n'                   => $vin['vout'],
                    'confirmations'       => $confirmations,
                
                    'spent'               => 1,
                    'spent_confirmations' => $confirmations,

                    'last_update'         => date("Y-m-d H:i:s"),
                ];

                // if it exists, merge it
                $cache_table = $this->utxo_cache_table_provider->__invoke();
                $existing_cache_record = $cache_table
                    ->where('address_reference', '=', $address_reference)
                    ->where('txid', '=', $vin['txid'])
                    ->where('n', '=', $vin['vout'])
                    ->lockForUpdate()
                    ->first(['id']);

                if ($existing_cache_record) {
                    // update the existing record - mark it spent
                    $cache_table = $this->utxo_cache_table_provider->__invoke();
                    // don't overwrite confirmations
                    unset($insert_vars['confirmations']);
                    $cache_table
                        ->where('id', $existing_cache_record->id)
                        ->update($insert_vars);
                } else {
                    // create a new UTXO record and mark it spent
                    $cache_table = $this->utxo_cache_table_provider->__invoke();
                    $cache_table->insert($insert_vars);
                }
            }
        }
    }
    
    protected function insertTXOutputsIntoCacheTable($address_reference, $txid, $vouts, $confirmations) {
        foreach($vouts as $vout) {
            if (isset($vout['scriptPubKey']) AND isset($vout['scriptPubKey']['type']) AND $vout['scriptPubKey']['type'] == 'pubkeyhash') {
                if (isset($vout['scriptPubKey']['addresses']) AND $vout['scriptPubKey']['addresses']) {
                    $value_sat = CurrencyUtil::valueToSatoshis($vout['value']);
                    $insert_vars = [
                        'address_reference'   => $address_reference,
                        'txid'                => $txid,
                        'n'                   => $vout['n'],
                        'confirmations'       => $confirmations,
                        'destination_address' => $vout['scriptPubKey']['addresses'][0],
                        'destination_value'   => $value_sat,
                        'script'              => $vout['scriptPubKey']['hex'],
                    
                        'last_update'         => date("Y-m-d H:i:s"),
                    ];

                    // if it exists, merge it
                    $cache_table = $this->utxo_cache_table_provider->__invoke();
                    $existing_cache_record = $cache_table
                        ->where('address_reference', '=', $address_reference)
                        ->where('txid', '=', $txid)
                        ->where('n', '=', $vout['n'])
                        ->lockForUpdate()
                        ->first(['id']);

                    if ($existing_cache_record) {
                        // update the existing record - fill in destination address, value and script
                        $cache_table = $this->utxo_cache_table_provider->__invoke();
                        $cache_table
                            ->where('id', $existing_cache_record->id)
                            ->update($insert_vars);
                    } else {
                        // create a new UTXO record
                        $cache_table = $this->utxo_cache_table_provider->__invoke();
                        $cache_table->insert($insert_vars);
                    }
                }
            }
        }

    }

    protected function normalizeBitcoindUTXORecord($unspent_txo_record) {
        return [
            'address'       => $unspent_txo_record->destination_address,
            'txid'          => $unspent_txo_record->txid, // $bitcoind_txo['txid'],
            'vout'          => $unspent_txo_record->n, // $bitcoind_vout['n'],
            'script'        => $unspent_txo_record->script, // $bitcoind_vout['scriptPubKey']['hex'],
            'amount'        => CurrencyUtil::satoshisToValue($unspent_txo_record->destination_value), // $bitcoind_vout['value'],
            'amount_sat'    => $unspent_txo_record->destination_value, // $bitcoind_vout['value'],
            'confirmations' => $unspent_txo_record->confirmations, // $bitcoind_txo['confirmations'],
            'confirmed'     => ($unspent_txo_record->confirmations > 0), // ($bitcoind_txo['confirmations'] > 0),
        ];
    }

        // -----------------------------------
        // database format:
        // 
        //   address_reference
        //   txid
        //   n
        //   confirmations
        //   script
        //   destination_address
        //   destination_value
        //   
        //   spent
        //   spent_confirmations
        //   
        //   last_update
        //   
        // -----------------------------------


}

