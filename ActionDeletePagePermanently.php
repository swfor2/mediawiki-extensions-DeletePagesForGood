<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Status\Status;
use MediaWiki\Storage\BlobStore;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IDatabase;
use MediaWiki\Category\Category;
use MediaWiki\Deferred\DeferredUpdates;

class ActionDeletePagePermanently extends FormAction {

	/**
	 * Hook handler for SkinTemplateNavigation::Universal
	 * @param SkinTemplate $sktemplate
	 * @param array &$links
	 */
	public static function onSkinTemplateNavigationUniversal( SkinTemplate $sktemplate, array &$links ) {
		$user = $sktemplate->getUser();
		$permissionManager = MediaWikiServices::getInstance()->getPermissionManager();
		
		if ( $permissionManager->userHasRight( $user, 'deleteperm' ) ) {
			$title = $sktemplate->getRelevantTitle();
			$request = $sktemplate->getRequest();
			$action = $request->getRawVal( 'action', 'view' );

			if ( self::canDeleteTitle( $title ) ) {
				$links['actions']['delete_page_permanently'] = [
					'class' => ( $action === 'delete_page_permanently' ) ? 'selected' : false,
					'text' => $sktemplate->msg( 'deletepagesforgood-delete_permanently' )->text(),
					'href' => $title->getLocalUrl( 'action=delete_page_permanently' )
				];
			}
		}
	}

	/** @inheritDoc */
	public function getName() {
		return 'delete_page_permanently';
	}

	/** @inheritDoc */
	public function doesWrites() {
		return true;
	}

	/** @inheritDoc */
	public function getDescription() {
		return '';
	}

	/** @inheritDoc */
	protected function usesOOUI() {
		return true;
	}

	/**
	 * @param Title $title
	 * @return bool
	 */
	public static function canDeleteTitle( Title $title ) {
		global $wgDeletePagesForGoodNamespaces;

		if ( $title->exists() && $title->getArticleID() !== 0 &&
			$title->getDBkey() !== '' &&
			$title->getNamespace() !== NS_SPECIAL &&
			isset( $wgDeletePagesForGoodNamespaces[ $title->getNamespace() ] ) &&
			$wgDeletePagesForGoodNamespaces[ $title->getNamespace() ]
		) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * @param mixed $data
	 * @return bool|string[]
	 */
	public function onSubmit( $data ) {
		if ( self::canDeleteTitle( $this->getTitle() ) ) {
			$this->deletePermanently( $this->getTitle() );
			return true;
		} else {
			return [ 'deletepagesforgood-del_impossible' ];
		}
	}

	/**
	 * @param Title $title
	 * @return bool|string
	 */
	public function deletePermanently( Title $title ) {
		$services = MediaWikiServices::getInstance();
		$ns = $title->getNamespace();
		$t = $title->getDBkey();
		$id = $title->getArticleID();
		$cats = $title->getParentCategories();
		$user = $this->getContext()->getUser();
		$reason = 'Page being permanently deleted';

		$dbw = $services->getDBLoadBalancer()->getConnection( DB_PRIMARY );

		$dbw->startAtomic( __METHOD__, $dbw::ATOMIC_CANCELABLE );

		/*
		 * First delete entries, which are in direct relation with the page:
		 */

		# Delete redirect...
		$dbw->delete( 'redirect', [ 'rd_from' => $id ], __METHOD__ );

		# Delete external links...
		$dbw->delete( 'externallinks', [ 'el_from' => $id ], __METHOD__ );

		# Delete language links...
		$dbw->delete( 'langlinks', [ 'll_from' => $id ], __METHOD__ );

		$dbType = $services->getMainConfig()->get( 'DBtype' );
		if ( $dbType !== 'postgres' && $dbType !== 'sqlite' ) {
			# Delete search index...
			$dbw->delete( 'searchindex', [ 'si_page' => $id ], __METHOD__ );
		}

		# Delete restrictions for the page
		$dbw->delete( 'page_restrictions', [ 'pr_page' => $id ], __METHOD__ );

		# Delete page links
		$dbw->delete( 'pagelinks', [ 'pl_from' => $id ], __METHOD__ );

		# Delete category links
		$dbw->delete( 'categorylinks', [ 'cl_from' => $id ], __METHOD__ );

		# Delete template links
		$dbw->delete( 'templatelinks', [ 'tl_from' => $id ], __METHOD__ );

		# For MediaWiki 1.45+, we only use the MCR (Multi-Content Revision) schema
		$revisionStore = $services->getRevisionStore();
		$blobStore = $services->getBlobStore();
		$revQuery = $revisionStore->getQueryInfo();

		$res = $dbw->select(
			$revQuery['tables'],
			$revQuery['fields'],
			[ 'rev_page' => $id ],
			__METHOD__,
			[],
			$revQuery['joins']
		);
		foreach ( $res as $row ) {
			$rev = $revisionStore->newRevisionFromRow( $row );
			$this->deleteSlotsPermanently( $dbw,
				$rev->getSlots()->getSlots(),
				$rev->getId(),
				$blobStore
			);
		}

		$arRevQuery = $revisionStore->getArchiveQueryInfo();
		$arRes = $dbw->select(
			$arRevQuery['tables'],
			$arRevQuery['fields'],
			[
				'ar_namespace' => $ns,
				'ar_title' => $t
			],
			__METHOD__,
			[],
			$arRevQuery['joins']
		);
		foreach ( $arRes as $arRow ) {
			$rev = $revisionStore->newRevisionFromArchiveRow( $arRow );
			$this->deleteSlotsPermanently( $dbw,
				$rev->getSlots()->getSlots(),
				$rev->getId(),
				$blobStore
			);
		}

		# In the table 'revision' : Delete all the revision of the page where 'rev_page' = $id
		$dbw->delete( 'revision', [ 'rev_page' => $id ], __METHOD__ );

		# Delete image links
		$dbw->delete( 'imagelinks', [ 'il_from' => $id ], __METHOD__ );

		/*
		 * then delete entries which are not in direct relation with the page:
		 */

		# Clean up recentchanges entries...
		$dbw->delete( 'recentchanges', [
			'rc_namespace' => $ns,
			'rc_title' => $t
		], __METHOD__ );

		# Clean up archive entries...
		$dbw->delete( 'archive', [
			'ar_namespace' => $ns,
			'ar_title' => $t
		], __METHOD__ );

		# Clean up log entries...
		$dbw->delete( 'logging', [
			'log_namespace' => $ns,
			'log_title' => $t
		], __METHOD__ );

		# Clean up watchlist...
		$dbw->delete( 'watchlist', [
			'wl_namespace' => $ns,
			'wl_title' => $t
		], __METHOD__ );

		$namespaceInfo = $services->getNamespaceInfo();
		$associatedNs = $namespaceInfo->getAssociated( $ns );
		if ( $associatedNs !== false ) {
			$dbw->delete( 'watchlist', [
				'wl_namespace' => $associatedNs,
				'wl_title' => $t
			], __METHOD__ );
		}

		# In the table 'page' : Delete the page entry
		$dbw->delete( 'page', [ 'page_id' => $id ], __METHOD__ );

		/*
		 * If the article belongs to a category, update category counts
		 */
		if ( !empty( $cats ) ) {
			foreach ( $cats as $parentcat => $currentarticle ) {
				$catname = explode( ':', $parentcat, 2 );
				if ( isset( $catname[1] ) ) {
					$categoryTitle = Title::newFromText( $catname[1], NS_CATEGORY );
					if ( $categoryTitle && $categoryTitle->exists() ) {
						DeferredUpdates::addCallableUpdate( function() use ( $categoryTitle ) {
							try {
								$category = Category::newFromTitle( $categoryTitle );
								if ( $category ) {
									$category->refreshCounts();
								}
							} catch ( Exception $e ) {
								// Log error but continue execution
								wfLogWarning( 'Failed to refresh category counts: ' . $e->getMessage() );
							}
						} );
					}
				}
			}
		}

		/*
		 * If an image is being deleted, some extra work needs to be done
		 */
		if ( $ns == NS_FILE ) {
			$file = $services->getRepoGroup()->findFile( $t );

			if ( $file ) {
				$repo = $file->getRepo();
				try {
					$status = $file->deleteFile( $reason, $user );
				} catch ( \LogicException $e ) {
					# non-writable repo, continue
					$status = Status::newGood();
				}
				if ( !$status->isOK() ) {
					$dbw->cancelAtomic( __METHOD__ );
					return false;
				}

				// Similar to LocalFileRestoreBatch::cleanup()
				$deletedFiles = [];
				$res = $dbw->select( 'filearchive', 'fa_storage_key', [ 'fa_name' => $t ], __METHOD__ );
				foreach ( $res as $row ) {
					$fileKey = $row->fa_storage_key;
					$filePath = $repo->getVirtualUrl( 'deleted' ) . '/' .
						rawurlencode( $repo->getDeletedHashPath( $fileKey ) . $fileKey );
					if ( $repo->fileExists( $filePath ) ) {
						$deletedFiles[] = $fileKey;
					}
				}

				# Clean the filearchive for the given filename:
				$dbw->delete( 'filearchive', [ 'fa_name' => $t ], __METHOD__ );

				try {
					$repo->cleanupDeletedBatch( $deletedFiles );
				} catch ( \LogicException $e ) {
					# non-writable repo, continue
				}
			}

			$linkCache = $services->getLinkCache();
			$linkCache->clear();
		}
		$dbw->endAtomic( __METHOD__ );
		return true;
	}

	/**
	 * In MCR schema, delete the slots corresponding to some revision.
	 *
	 * @param IDatabase $dbw Database handle
	 * @param SlotRecord[] $slots Slots
	 * @param int $revId Revision ID
	 * @param BlobStore $blobStore MediaWiki service BlobStore
	 * @return bool true if the content can be deleted, false otherwise
	 */
	private function deleteSlotsPermanently( $dbw, $slots, $revId, $blobStore ) {
		foreach ( $slots as $role => $slot ) {
			if ( $this->shouldDeleteContent( $dbw, $revId, $slot->getContentId() ) ) {
				$address = $slot->getAddress();
				if ( $address ) {
					try {
						$textId = $blobStore->getTextIdFromAddress( $address );
						if ( $textId ) {
							$dbw->delete( 'text', [ 'old_id' => $textId ], __METHOD__ );
						}
					} catch ( Exception $e ) {
						// Address might not be in text table format, continue
					}
				}
				$dbw->delete( 'content',
					[ 'content_id' => $slot->getContentId() ],
					__METHOD__
				);
			}
		}

		// This may orphan content types other than text
		$dbw->delete( 'slots',
			[ 'slot_revision_id' => $revId ],
			__METHOD__
		);
		
		return true;
	}

	/**
	 * Determines if a particular piece of content should be deleted. Deleting requires querying
	 * if the content is used in any other revisions. This can be slow, and the caller will have
	 * a transaction open on the primary database. Setting $wgDeletePagesForGoodDeleteContent to
	 * false is faster, because it skips the query and leaves the content alone. But it leaves
	 * orphaned content in storage.
	 *
	 * @param IDatabase $dbw Database handle
	 * @param int $revId Revision ID
	 * @param int $contentId Content ID to consider
	 * @return bool true if the content can be deleted, false otherwise
	 */
	private function shouldDeleteContent( $dbw, $revId, $contentId ) {
		global $wgDeletePagesForGoodDeleteContent;

		if ( !$wgDeletePagesForGoodDeleteContent ) {
			return false;
		}

		$count = $dbw->selectRowCount(
			'slots',
			'*',
			[
				'slot_content_id' => $contentId,
				$dbw->expr( 'slot_revision_id', '!=', $revId )
			],
			__METHOD__
		);
		return $count == 0;
	}

	/**
	 * Returns the name that goes in the \<h1\> page title
	 *
	 * @return string
	 */
	protected function getPageTitle() {
		return $this->msg( 'deletepagesforgood-deletepagetitle', $this->getTitle()->getPrefixedText() );
	}

	/**
	 * @param HTMLForm $form
	 */
	protected function alterForm( HTMLForm $form ) {
		$title = $this->getTitle();
		$output = $this->getOutput();

		$output->addBacklinkSubtitle( $title );
		$form->addPreHtml( $this->msg( 'confirmdeletetext' )->parseAsBlock() );

		$form->addPreHtml(
			$this->msg( 'deletepagesforgood-ask_deletion' )->parseAsBlock()
		);

		$form->setSubmitTextMsg( 'deletepagesforgood-yes' );
	}

	/** @inheritDoc */
	public function getRestriction() {
		return 'deleteperm';
	}

	/**
	 * @return bool
	 */
	public function onSuccess() {
		$output = $this->getOutput();
		$output->addHTML( $this->msg( 'deletepagesforgood-del_done' )->escaped() );
		return false;
	}
}
