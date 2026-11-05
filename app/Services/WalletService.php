<?php
declare(strict_types=1);
namespace GigHub\Services;
use GigHub\Repositories\WalletRepository;
use PDO;
use PDOException;
use Throwable;

class WalletService
{
    public function __construct(private readonly PDO $pdo, private readonly WalletRepository $wallets) {}

    public function getWalletOverview(int $userId, string|int $roleOrPage = 'client', int $page = 1, ?int $jobId = null): array
    {
        if (is_int($roleOrPage)) {
            $role = 'client';
            $page = $roleOrPage;
        } else {
            $role = $roleOrPage;
        }

        if (!$this->wallets->eligibleUser($userId, $role)) {
            throw new WalletException(['form' => 'Your account cannot access this wallet.'], 403);
        }

        if ($role === 'freelancer') {
            $this->wallets->ensureWallet($userId);
        }

        $wallet = $this->wallets->getWallet($userId);
        if ($wallet === false) {
            $wallet = ['user_id' => $userId, 'balance' => '0.00', 'updated_at' => null];
        }

        $returnJobId = null;
        if ($jobId !== null && $jobId > 0) {
            if ($role === 'client' && method_exists($this->wallets, 'isClientJobOwner') && $this->wallets->isClientJobOwner($userId, $jobId)) {
                $returnJobId = $jobId;
            } elseif ($role === 'freelancer' && method_exists($this->wallets, 'isFreelancerAwardedJob') && $this->wallets->isFreelancerAwardedJob($userId, $jobId)) {
                $returnJobId = $jobId;
            }
        }

        $hasDemoCredit = ($role === 'client') ? $this->wallets->hasDemoCredit($userId) : false;
        $entries = $this->wallets->getEntries($userId, $page);
        $hasNext = count($entries) > 20;
        if ($hasNext) {
            $entries = array_slice($entries, 0, 20);
        }
        return [
            'wallet' => $wallet,
            'entries' => $entries,
            'has_demo_credit' => $hasDemoCredit,
            'page' => $page,
            'has_next' => $hasNext,
            'role' => $role,
            'return_job_id' => $returnJobId,
        ];
    }

    public function claimDemoCredit(int $clientId): void
    {
        $owns = !$this->pdo->inTransaction();
        $started = false;
        try {
            if ($owns) {
                $this->pdo->beginTransaction();
            } else {
                $this->pdo->exec('SAVEPOINT gighub_wallet');
            }
            $started = true;

            // 1. Lock user/client eligibility
            if (!$this->wallets->eligibleClient($clientId, true)) {
                throw new WalletException(['form' => 'Your account cannot receive demo credits.'], 403);
            }

            // 2. Check if demo credit already exists under lock
            if ($this->wallets->hasDemoCredit($clientId, true)) {
                throw new WalletException(['form' => 'Demo credit has already been issued to your account.'], 409);
            }

            // 3. Ensure wallet row exists and lock it
            $this->wallets->ensureWallet($clientId);
            $wallet = $this->wallets->getWallet($clientId, true);
            if ($wallet === false) {
                throw new WalletException(['form' => 'Wallet could not be initialized.'], 500);
            }

            // 4. Update balance
            $updated = $this->wallets->creditBalance($clientId, '10000.00');
            if ($updated < 1) {
                throw new WalletException(['form' => 'Failed to update wallet balance.'], 500);
            }

            // 5. Append append-only ledger entry with unique operation key
            $this->wallets->insertEntry($clientId, $clientId, '10000.00', 'demo:user:' . $clientId);

            if ($owns) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT gighub_wallet');
            }
        } catch (Throwable $error) {
            if ($started && $this->pdo->inTransaction()) {
                if ($owns) {
                    $this->pdo->rollBack();
                } else {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT gighub_wallet');
                    $this->pdo->exec('RELEASE SAVEPOINT gighub_wallet');
                }
            }
            if ($error instanceof WalletException) {
                throw $error;
            }
            // Catch concurrent duplicate key violation on demo_credit_user_id or operation_key
            if ($error instanceof PDOException) {
                $code = (string)$error->getCode();
                $msg = $error->getMessage();
                if ($code === '23000' || str_contains($msg, 'Duplicate entry') || (isset($error->errorInfo[1]) && (int)$error->errorInfo[1] === 1062)) {
                    throw new WalletException(['form' => 'Demo credit has already been issued to your account.'], 409);
                }
            }
            throw new \RuntimeException('Wallet service is temporarily unavailable.');
        }
    }
}
