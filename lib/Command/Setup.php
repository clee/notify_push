<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2020 Robin Appelman <robin@icewind.nl>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\NotifyPush\Command;

use OC\Core\Command\Base;
use OCA\NotifyPush\Queue\IQueue;
use OCA\NotifyPush\Queue\RedisQueue;
use OCP\Files\Config\IUserMountCache;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IDBConnection;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Setup extends Base {
	private $config;
	private $queue;
	private $clientService;
	private $mountCache;
	private $connection;

	public function __construct(
		IConfig $config,
		IQueue $queue,
		IClientService $clientService,
		IUserMountCache $mountCache,
		IDBConnection $connection
	) {
		parent::__construct();
		$this->config = $config;
		$this->queue = $queue;
		$this->clientService = $clientService;
		$this->mountCache = $mountCache;
		$this->connection = $connection;
	}


	protected function configure() {
		$this
			->setName('notify_push:setup')
			->setDescription('Configure push server')
			->addArgument('server', InputArgument::REQUIRED, "url of the push server");
		parent::configure();
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		if ($this->queue instanceof RedisQueue) {
			$output->writeln("<info>✓ redis is configured</info>");
		} else {
			$output->writeln("<error>🗴 redis is not configured</error>");
			return 1;
		}

		// we test if the push server is listening to redis by sending and retrieving a random number
		$server = $input->getArgument("server");

		$cookie = rand(1, pow(2, 30));

		$this->queue->push('notify_test_cookie', $cookie);
		$this->config->setAppValue('notify_push', 'cookie', (string)$cookie);

		$client = $this->clientService->newClient();

		try {
			$retrievedCookie = (int)$client->get($server . '/cookie_test')->getBody();
		} catch (\Exception $e) {
			$msg = $e->getMessage();
			$output->writeln("<error>🗴 can't connect to push server: $msg</error>");
			return 1;
		}

		if ($cookie === $retrievedCookie) {
			$output->writeln("<info>✓ push server is receiving redis messages</info>");
		} else {
			$output->writeln("<error>🗴 push server is not receiving redis messages</error>");
			return 1;
		}

		// test if the push server can load storage mappings from the db
		[$storageId, $count] = $this->getStorageIdForTest();
		try {
			$retrievedCount = (int)$client->get($server . '/mapping_test/' . $storageId)->getBody();
		} catch (\Exception $e) {
			$msg = $e->getMessage();
			$output->writeln("<error>🗴 can't connect to push server: $msg</error>");
			return 1;
		}

		if ($count === $retrievedCount) {
			$output->writeln("<info>✓ push server can load mount info from database</info>");
		} else {
			$output->writeln("<error>🗴 push server can't load mount info from database</error>");
			return 1;
		}

		// test if the push server can reach nextcloud by having it request the cookie
		try {
			$retrievedCookie = (int)$client->get($server . '/reverse_cookie_test')->getBody();
		} catch (\Exception $e) {
			$msg = $e->getMessage();
			$output->writeln("<error>🗴 can't connect to push server: $msg</error>");
			return 1;
		}

		if ($cookie === $retrievedCookie) {
			$output->writeln("<info>✓ push server can connect to the Nextcloud server</info>");
		} else {
			$output->writeln("<error>🗴 push server can't connect to the Nextcloud server</error>");
			return 1;
		}

		$this->config->setAppValue('notify_push', 'base_endpoint', $server);
		$output->writeln("  configuration saved");

		return 0;
	}

	private function getStorageIdForTest() {
		$query = $this->connection->getQueryBuilder();
		$query->select('storage_id', $query->func()->count())
			->from('mounts', 'm')
			->innerJoin('m', 'filecache', 'f', $query->expr()->eq('root_id', 'fileid'))
			->where($query->expr()->eq('path_hash', $query->createNamedParameter(md5(''))))
			->groupBy('storage_id')
			->setMaxResults(1);

		return $query->execute()->fetchNumeric();
	}

}
