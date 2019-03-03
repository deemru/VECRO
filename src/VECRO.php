<?php

namespace deemru;

use deemru\WavesKit;
use deemru\Pairs;

class VECRO
{
    public function __construct( $wk, $pairsFile, $alias, $aliasInit, $aliasRandom, $assetInit, $assetRandom )
    {
        require_once __DIR__ . '/../support/secqru_flock.php';
        $this->lock = new \secqru_flock( "$pairsFile.lock" );
        if( false === $this->lock->open() )
            exit( $wk->log( 'e', 'flock failed, already running?' ) );

        $this->wk = $wk;
        $this->used = new Pairs( $pairsFile, 'used', true, 'TEXT UNIQUE|TEXT|0|0' );
        $this->db = $this->used;
        $this->queue = new Pairs( $this->db->db(), 'queue', true, 'TEXT UNIQUE|TEXT|0|0' );
        $this->done = new Pairs( $this->db->db(), 'done', true, 'TEXT UNIQUE|TEXT|0|0' );
        $this->rid = new Pairs( $this->db->db(), 'rid', true, 'TEXT UNIQUE|TEXT|0|0' );
        $this->wk->setPairsDatabase( $this->db->db() );

        $this->address = $wk->getAddress();
        $this->alias = $alias;
        $chainId = $wk->getChainId();
        $this->aliasInit = "alias:$chainId:$aliasInit";
        $this->aliasRandom = "alias:$chainId:$aliasRandom";
        $this->assetInit = $assetInit;
        $this->assetRandom = $assetRandom;

        $this->triggerCost = 500000;
        $this->triggerMaxCost = 100000000;

        $this->sureness = 2;

        $this->iSig = 0;
        $this->iRaw = 1;
        $this->iTrig = 2;
        $this->iInit = 3;

        $this->codeR = 'R';
        $this->codeS = 'S';
        $this->codeSize = 45;
    }

    private function prepare()
    {
        while( $this->isUnconfirmed() )
        {
            $this->wk->log( 'i', "wait unconfirmed" );
            sleep( 5 );
        }
    }

    private function isUnconfirmed()
    {
        if( false === ( $utxs = $this->wk->fetch( '/transactions/unconfirmed' ) ) ||
            false === ( $utxs = $this->wk->json_decode( $utxs ) ) )
            $this->error( 'fetch failed' );

        foreach( $utxs as $utx )
            if( $utx['type'] === 4 && $utx['sender'] === $this->address )
                return true;

        return false;
    }

    private function error( $message )
    {
        trigger_error( $this->wk->log( 'e', $message ), E_USER_ERROR );
    }

    public function run()
    {
        $this->prepare();
        $this->wk->txMonitor( function( $wk, $refreshed, $newTransactions ){ return $this->proc( $wk, $refreshed, $newTransactions ); } );
    }

    private function RS( $triggerInitTx, $msg = null )
    {
        $this->wk->setRSEED( $triggerInitTx['id'] . $this->address . $this->alias );
        return $this->wk->sign( $msg );
    }

    private function init( $tx )
    {
        $txid = $tx['id'];
        $rawid = $this->wk->base58Decode( $txid );
        $triggerInitTx = $tx;

        if( isset( $triggerInitTx['assetId'] ) )
            return 'assetId != WAVES';
        if( $triggerInitTx['amount'] < $this->triggerCost )
            return "amount < {$this->triggerCost}";

        $R = $this->RS( $triggerInitTx );
        $R58 = $this->wk->base58Encode( $R );
        $Rcode = $this->wk->base58Encode( $this->codeR . $R );
        if( strlen( $Rcode ) !== $this->codeSize )
            $this->error( "init ($txid) strlen( Rcode ) !== codeSize" );
        $attachment = $this->wk->base58Encode( $Rcode );

        $initTx = $this->wk->txTransfer( $triggerInitTx['sender'], 1, $this->assetInit, [ 'fee' => min( $this->triggerMaxCost, $triggerInitTx['amount'] ), 'attachment' => $attachment ] );
        $initTxBodyBytes = $this->wk->txBody( $initTx ) . $this->wk->base58Decode( $txid );
        $initTx['proofs'][$this->iSig] = $this->wk->base58Encode( $this->wk->sign( $initTxBodyBytes ) );
        $initTx['proofs'][$this->iRaw] = $R58;
        $initTx['proofs'][$this->iTrig] = $txid;

        if( false === $this->rid->setKeyValue( $R, $rawid, 's' ) )
            $this->error( "init ($txid) setKeyValue failed" );

        if( false === ( $initTx = $this->wk->txBroadcast( $initTx ) ) )
        {
            $this->wk->log( 'w', "init ($txid) txBroadcast failed" );
            return false;
        }

        $this->wk->log( 's', "init ($txid) >> ({$initTx['id']})" );
        return true;
    }

    private function random( $tx )
    {
        $txid = $tx['id'];
        $triggerRandomTx = $tx;

        if( isset( $triggerRandomTx['assetId'] ) )
            return 'assetId != WAVES';
        if( $triggerRandomTx['amount'] < $this->triggerCost )
            return "amount < {$this->triggerCost}";

        $msg = $this->wk->base58Decode( $triggerRandomTx['attachment'] );
        if( strlen( $msg ) < $this->codeSize )
            return 'attachment < sizeR';
        $Rcode = substr( $msg, 0, $this->codeSize );
        if( false === ( $R = $this->wk->base58Decode( $Rcode ) ) )
            return 'can not decode R-value';
        if( $R[0] !== $this->codeR )
            return 'wrong prefix';
        $R = substr( $R, 1 );
        if( false === ( $rawid = $this->rid->getValue( $R, 's' ) ) )
            return 'R-value not found';

        if( false === ( $doneTx = $this->done->getValue( $rawid, 'j' ) ) )
        {
            $this->wk->log( 'w', "random ($txid) triggerInitTx not found in done" );
            return false;
        }

        $initTx = $doneTx['status'];
        $triggerInitTx = $doneTx['tx'];

        if( !is_array( $initTx ) )
            return "initTx status: $initTx";

        if( $triggerInitTx['sender'] !== $triggerRandomTx['sender'] )
            return "not equal senders";

        $userStr = substr( $msg, $this->codeSize );
        if( false !== ( $used = $this->used->getValue( $R, 's' ) ) )
        {
            if( $userStr === $used )
                $this->wk->log( 'w', "random ($txid) same R-value with same msg" );
            else
                return 'R-value has already been used';
        }
        else if( false === $this->used->setKeyValue( $R, $userStr, 's' ) )
            $this->error( "random ($txid) setKeyValue failed" );

        $RS = $this->RS( $triggerInitTx, $msg );

        if( substr( $RS, 0, 32 ) !== $R )
            return "not equal R-values";

        $S = substr( $RS, 32 );
        $S58 = $this->wk->base58Encode( $S );
        $Scode = $this->wk->base58Encode( $this->codeS . $S );
        if( strlen( $Scode ) !== $this->codeSize )
            $this->error( "random ($txid) strlen( Scode ) !== codeSize" );
        $attachment = $this->wk->base58Encode( $Scode );
        $initTxId = $initTx['id'];

        $randomTx = $this->wk->txTransfer( $triggerRandomTx['sender'], 1, $this->assetRandom, [ 'fee' => min( $this->triggerMaxCost, $triggerRandomTx['amount'] ), 'attachment' => $attachment ] );
        $randomTxBodyBytes = $this->wk->txBody( $randomTx ) . $this->wk->base58Decode( $txid ) . $this->wk->base58Decode( $initTxId );
        $randomTx['proofs'][$this->iSig] = $this->wk->base58Encode( $this->wk->sign( $randomTxBodyBytes ) );
        $randomTx['proofs'][$this->iRaw] = $S58;
        $randomTx['proofs'][$this->iTrig] = $txid;
        $randomTx['proofs'][$this->iInit] = $initTxId;

        if( false === ( $randomTx = $this->wk->txBroadcast( $randomTx ) ) )
        {
            $this->wk->log( 'w', "random ($txid) txBroadcast failed" );
            return false;
        }

        $this->wk->log( 's', "random ($txid) >> ({$randomTx['id']})" );
        return true;
    }

    private function queue( $tx )
    {
        $qtxid = $tx['id'];
        $rawid = $this->wk->base58Decode( $qtxid );
        if( false !== ( $qtx = $this->queue->getValue( $rawid, 'j' ) ) )
        {
            $status = isset( $qtx['status'] ) ? $qtx['status'] : 'processing...';
            $this->wk->log( 'w', "queue ($qtxid) already in queue ($status)" );
            return;
        }
        if( false !== ( $qtxdone = $this->done->getValue( $rawid, 'j' ) ) )
        {
            $doneId = $qtxdone['status']['id'];
            $this->wk->log( 'w', "queue ($qtxid) already in done ($doneId)" );
            return;
        }

        $qtx = [];
        $qtx['tx'] = $tx;
        if( false === $this->queue->setKeyValue( $rawid, $qtx, 'j' ) )
            $this->error( "queue ($qtxid) setKeyValue failed" );

        $this->wk->log( 'i', "queue ($qtxid)" );
    }

    private function done( $tx )
    {
        if( isset( $tx['proofs'][$this->iTrig] ) )
        {
            $qtxid = $tx['proofs'][$this->iTrig];
            $rawid = $this->wk->base58Decode( $qtxid );
            if( false !== ( $qtxdone = $this->done->getValue( $rawid, 'j' ) ) )
            {
                $doneId = $qtxdone['status']['id'];
                $this->wk->log( 'w', "done ($qtxid) already in done ($doneId)" );
                return;
            }
            if( false === ( $qtx = $this->queue->getValue( $rawid, 'j' ) ) )
            {
                $this->wk->log( 'w', "done ($qtxid) not found in queue" );

                $qtx = [];
                if( false === ( $qtx['tx'] = $this->wk->getTransactionById( $qtxid ) ) )
                    $this->error( "done ($qtxid) getTransactionById() failed" );
            }

            $this->db->begin();
            {
                if( false === $this->queue->unsetKeyValue( $rawid, $qtx, 'j' ) )
                    $this->error( "done ($qtxid) unsetKeyValue failed" );

                $qtx['status'] = $tx;

                if( false === $this->done->setKeyValue( $rawid, $qtx, 'j' ) )
                    $this->error( "done ($qtxid) setKeyValue failed" );

                if( !isset( $tx['proofs'][$this->iInit] ) )
                // R
                {
                    $R = $this->wk->base58Decode( $tx['proofs'][$this->iRaw] );
                    if( false === $this->rid->setKeyValue( $R, $rawid, 's' ) )
                        $this->error( "done ($qtxid) setKeyValue failed" );
                }
                else
                // S
                {
                    $msg = $this->wk->base58Decode( $qtx['tx']['attachment'] );
                    $Rcode = substr( $msg, 0, $this->codeSize );
                    $R = $this->wk->base58Decode( $Rcode );
                    $R = substr( $R, 1 );
                    $userStr = substr( $msg, $this->codeSize );
                    if( false !== ( $used = $this->used->getValue( $R, 's' ) ) && $userStr !== $used )
                    {
                        $asset = $tx['assetId'];
                        if( $asset === $this->assetInit || $asset === $this->assetRandom )
                            $this->error( "done ($qtxid) userStr !== used" );
                        else
                            $this->wk->log( 'w', "done ($qtxid) userStr !== used ($asset)" );
                    }
                    if( false === $this->used->setKeyValue( $R, $userStr, 's' ) )
                        $this->error( "done ($qtxid) setKeyValue failed" );
                }
            }
            $this->db->commit();

            $this->wk->log( 's', "done ($qtxid)" );
        }
    }

    private function proc( $wk, $refreshed, $newTransactions )
    {
        if( $refreshed )
        {
            foreach( $newTransactions as $tx )
            {
                if( $tx['type'] !== 4 )
                    continue;

                $sender = $tx['sender'];
                $recipient = $tx['recipient'];

                if( $recipient === $this->aliasInit || $recipient === $this->aliasRandom )
                    $this->queue( $tx );
                else if( $sender === $this->address )
                    $this->done( $tx );
            }
        }

        $queue = $this->getQueue();
        if( $queue === false )
            $this->error( 'proc: getQueue() failed' );

        $work = [];
        foreach( $queue as $rec )
        {
            if( false === ( $qtx = $this->wk->json_decode( $rec['value'] ) ) )
                $this->error( 'proc: json_decode failed' );

            if( isset( $qtx['status'] ) )
            {
                if( $qtx['status'] !== 'sent' )
                    $this->error( 'proc: unknown status in queue' );
                continue;
            }

            $work[] = $qtx;
        }

        foreach( $work as $qtx )
        {
            $tx = $qtx['tx'];
            $qtxid = $tx['id'];
            $rawid = $wk->base58Decode( $qtxid );
            $recipient = $tx['recipient'];

            if( $recipient !== $this->aliasInit && $recipient !== $this->aliasRandom )
                $this->error( "proc ($qtxid) unknown recipient in queue" );

            $status = $recipient === $this->aliasInit ? $this->init( $tx ) : $this->random( $tx );

            if( $status === true )
            {
                $qtx['status'] = 'sent';
                if( false === $this->queue->setKeyValue( $rawid, $qtx, 'j' ) )
                    $this->error( "proc ($qtxid) setKeyValue failed" );
                continue;
            }
            else if( $status === false )
                continue;

            $this->wk->log( 'w', "proc ($qtxid) with status: $status" );

            // status not 'sent' but done
            $this->db->begin();
            {
                if( false === $this->queue->unsetKeyValue( $rawid, $qtx, 'j' ) )
                    $this->error( "proc ($qtxid) unsetKeyValue failed" );

                $qtx['status'] = $status;

                if( false === $this->done->setKeyValue( $rawid, $qtx, 'j' ) )
                    $this->error( "proc ($qtxid) setKeyValue failed" );
            }
            $this->db->commit();
        }

        return 30;
    }

    private function getQueue()
    {
        return $this->queue->query( 'SELECT * FROM queue ORDER BY rowid ASC' );
    }
}
