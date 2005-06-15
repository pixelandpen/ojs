<?php

/**
 * NativeImportDom.inc.php
 *
 * Copyright (c) 2003-2005 The Public Knowledge Project
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @package plugins
 *
 * Native import/export plugin DOM functions for import
 *
 * $Id$
 */

import('xml.XMLWriter');

class NativeImportDom {
	function importIssues(&$journal, &$issueNodes, &$issues, &$errors, &$user, $isCommandLine) {
		$dependentItems = array();
		$errors = array();
		$issues = array();
		$hasErrors = false;
		foreach ($issueNodes as $issueNode) {
			$result = &NativeImportDom::importIssue(&$journal, &$issueNode, &$issue, &$issueErrors, &$user, $isCommandLine, &$dependentItems);
			if ($result) {
				// Success. Add this issue to the list of
				// successfully imported issues.
				$issues[] = $issue;
			} else {
				// Failure. Record the errors and keep trying
				// with the next issue, even though we will just
				// delete any successful issues, so that the
				// user can be presented with as many relevant
				// error messages as possible.
				$errors = array_merge($errors, $issueErrors);
				$hasErrors = true;
			}
		}
		if ($hasErrors) {
			// There were errors. Delete all the issues we've
			// successfully created.
			$issueDao = &DAORegistry::getDAO('IssueDAO');
			foreach ($issues as $issue) {
				$issueDao->deleteIssue($issue);
			}
			return false;
		}
		return true;
	}

	function importIssue(&$journal, &$issueNode, &$issue, &$errors, &$user, $isCommandLine, &$dependentItems = null) {
		$errors = array();
		$issue = null;
		$hasErrors = false;

		if ($dependentItems === null) {
			$dependentItems = array();
			$responsibleForCleanup = true;
		} else {
			$responsibleForCleanup = false;
		}

		$issueDao = &DAORegistry::getDAO('IssueDAO');
		$issue = new Issue();
		$issue->setJournalId($journal->getJournalId());

		/* --- Set title, description, volume, number, and year --- */

		if (($node = $issueNode->getChildByName('title'))) {
			$issue->setTitle($node->getValue());
		} else {
			$errors[] = array('plugins.importexport.native.import.error.titleMissing', array());
			// Set a placeholder title so that further errors are
			// somewhat meaningful; this placeholder will not be
			// inserted into the database.
			$issue->setTitle(Locale::translate('plugins.importexport.native.import.error.defaultTitle'));
			$hasErrors = true;
		}
		if (($node = $issueNode->getChildByName('description'))) $issue->setDescription($node->getValue());

		if (($node = $issueNode->getChildByName('volume'))) $issue->setVolume($node->getValue());
		if (($node = $issueNode->getChildByName('number'))) $issue->setNumber($node->getValue());
		if (($node = $issueNode->getChildByName('year'))) $issue->setYear($node->getValue());

		/* --- Set attributes: Identification type, published, current, public ID --- */

		switch(($value = $issueNode->getAttribute('identification'))) {
			case 'num_vol_year':
				$issue->setLabelFormat(ISSUE_LABEL_NUM_VOL_YEAR);
				break;
			case 'vol_year':
				$issue->setLabelFormat(ISSUE_LABEL_VOL_YEAR);
				break;
			case 'year':
				$issue->setLabelFormat(ISSUE_LABEL_YEAR);
				break;
			case 'title':
			case '':
			case null:
				$issue->setLabelFormat(ISSUE_LABEL_TITLE);
				break;
			default:
				$errors[] = array('plugins.importexport.native.import.error.unknownIdentificationType', array('identificationType' => $value, 'issueTitle' => $issue->getTitle()));
				$hasErrors = true;
				break;
		}

		switch(($value = $issueNode->getAttribute('published'))) {
			case 'true':
				$issue->setPublished(true);
				break;
			case 'false':
			case '':
			case null:
				$issue->setPublished(false);
				break;
			default:
				$errors[] = array('plugins.importexport.native.import.error.invalidBooleanValue', array('value' => $value));
				$hasErrors = true;
				break;
		}

		switch(($value = $issueNode->getAttribute('current'))) {
			case 'true':
				$issue->setCurrent(true);
				break;
			case 'false':
			case '':
			case null:
				$issue->setCurrent(false);
				break;
			default:
				$errors[] = array('plugins.importexport.native.import.error.invalidBooleanValue', array('value' => $value));
				$hasErrors = true;
				break;
		}

		if (($value = $issueNode->getAttribute('public_id')) != '') {
			$anotherIssue = $issueDao->getIssueByPublicIssueId($value, $journal->getJournalId());
			if ($anotherIssue) {
				$errors[] = array('plugins.importexport.native.import.error.duplicatePublicId', array('issueTitle' => $issue->getIssueIdentification(), 'otherIssueTitle' => $anotherIssue->getIssueIdentification()));
				$hasErrors = true;
			} else {
				$issue->setPublicIssueId($value);
			}
		}

		/* --- Access Status --- */

		$node = $issueNode->getChildByName('open_access');
		$issue->setAccessStatus($node?'true':'false');

		if (($node = $issueNode->getChildByName('access_date'))) {
			$accessDate = strtotime($node->getValue());
			if ($accessDate === -1) {
				$errors[] = array('plugins.importexport.native.import.error.invalidDate', array('value' => $node->getValue()));
				$hasErrors = true;
			} else {
				$issue->setOpenAccessDate($accessDate);
			}
		}

		/* --- Temporarily set values that may be changed later --- */

		$issue->setShowCoverPage(false);

		/* --- All processing that does not require an inserted issue ID
		   --- has been performed by this point. If there were no errors
		   --- then insert the issue and carry on. If there were errors,
		   --- then abort without performing the insertion. */

		if ($hasErrors) {
			$issue = null;
			return false;
		} else {
			if ($issue->getCurrent()) {
				$issueDao->updateCurrentIssue($journal->getJournalId());
			}
			$issue->setIssueId($issueDao->insertIssue(&$issue));
			$dependentItems[] = array('issue', $issue);
		}

		/* --- Handle cover --- */

		if (($node = $issueNode->getChildByName('cover'))) {
			if (!NativeImportDom::handleCoverNode(&$journal, &$node, &$issue, &$coverErrors, $isCommandLine)) {
				$errors = array_merge($errors, $coverErrors);
				$hasErrors = true;
			}
		}

		/* --- Handle sections --- */
		for ($index = 0; ($node = $issueNode->getChildByName('section', $index)); $index++) {
			if (!NativeImportDom::handleSectionNode(&$journal, &$node, &$issue, &$sectionErrors, &$user, $isCommandLine, $dependentItems)) {
				$errors = array_merge($errors, $sectionErrors);
				$hasErrors = true;
			}
		}

		/* --- See if any errors occurred since last time we checked.
		   --- If so, delete the created issue and return failure.
		   --- Otherwise, the whole process was successful. */

		if ($hasErrors) {
			$issueDao->deleteIssue($issue);
			$issue = null;
			if ($responsibleForCleanup) NativeImportDom::cleanupFailure(&$dependentItems);
			return false;
		}

		$issueDao->updateIssue($issue);
		return true;
	}

	function handleCoverNode(&$journal, &$coverNode, &$issue, &$errors, $isCommandLine) {
		$errors = array();
		$hasErrors = false;

		$issue->setShowCoverPage(true);

		if (($node = $coverNode->getChildByName('caption'))) $issue->setCoverPageDescription($node->getValue());

		if (($node = $coverNode->getChildByName('image'))) {
			import('file.PublicFileManager');
			$publicFileManager = new PublicFileManager();
			$newName = 'cover_' . $issue->getIssueId() . '.';

			if (($href = $node->getChildByName('href'))) {
				$url = $href->getAttribute('src');
				if ($isCommandLine || NativeImportDom::isAllowedMethod($url)) {
					$originalName = basename($url);
					$newName .= $publicFileManager->getExtension($originalName);
					if (!$publicFileManager->copyJournalFile($journal->getJournalId(), $url, $newName)) {
						$errors[] = array('plugins.importexport.native.import.error.couldNotCopy', array('url' => $url, 'newName' => $newName, 'issueTitle' => $issue->getIssueIdentification()));
						$hasErrors = true;
					}
					$issue->setFileName($newName);
					$issue->setOriginalFileName($originalName);
				}
			}
			if (($embed = $node->getChildByName('embed'))) {
				if (($type = $embed->getAttribute('encoding')) !== 'base64') {
					$errors[] = array('plugins.importexport.native.import.error.unknownEncoding', array('type' => $type, 'issueTitle' => $issue->getIssueIdentification()));
					$hasErrors = true;
				} else {
					$originalName = $embed->getAttribute('filename');
					$newName .= $publicFileManager->getExtension($originalFileName);
					$issue->setFileName($newName);
					$issue->setOriginalFileName($originalName);
					if ($publicFileManager->writeJournalFile($journal->getJournalId(), $newName, base64_decode($embed->getValue()))===false) {
						$errors[] = array('plugins.importexport.native.import.error.couldNotWriteFile', array('originalName' => $originalName, 'newName' => $newName, 'issueTitle' => $issue->getIssueIdentification()));
						$hasErrors = true;
					}
				}
			}
		}

		if ($hasErrors) {
			return false;
		}
		return true;
	}

	function isAllowedMethod($url) {
		$allowedPrefixes = array(
			'http://',
			'ftp://',
			'https://',
			'ftps://'
		);
		foreach ($allowedPrefixes as $prefix) {
			if (substr($url, 0, strlen($prefix)) === $prefix) return true;
		}
		return false;
	}

	function handleSectionNode(&$journal, &$sectionNode, &$issue, &$errors, &$user, $isCommandLine, $dependentItems) {
		$sectionDao = &DAORegistry::getDAO('SectionDAO');

		$errors = array();

		// The following page or two is responsible for locating an
		// existing section based on title and/or abbrev, or, if none
		// can be found, creating a new one.

		if (!($titleNode = $sectionNode->getChildByName('title'))) {
			$errors[] = array('plugins.importexport.native.import.error.sectionTitleMissing', array('issueTitle' => $issue->getIssueIdentification()));
			return false;
		}
		$title = $titleNode->getValue();

		if (($abbrevNode = $sectionNode->getChildByName('abbrev'))) $abbrev = $abbrevNode->getValue();
		else $abbrev = null;

		// $title and, optionally, $abbrev contain information that can
		// be used to locate an existing section. Otherwise, we'll
		// create a new one. If $title and $abbrev each match an
		// existing section, but not the same section, throw an error.
		$section = $abbrevSection = null;
		if (!empty($title) && !empty($abbrev)) {
			$section = $sectionDao->getSectionByTitleAndAbbrev($title, $abbrev, $journal->getJournalId());
			if (!$section) $abbrevSection = $sectionDao->getSectionByAbbrev($abbrev, $journal->getJournalId());
		}
		if (!$section) {
			$section = $sectionDao->getSectionByTitle($title, $journal->getJournalId());
			if ($section && $abbrevSection && $section->getSectionId() != $abbrevSection->getSectionId()) {
				// Mismatching sections found. Throw an error.
				$errors[] = array('plugins.importexport.native.import.error.sectionMismatch', array('sectionTitle' => $title, 'sectionAbbrev' => $abbrev, 'issueTitle' => $issue->getIssueIdentification()));
				return false;
			}
			if (!$section) {
				// The section was not matched. Create one.
				// Note that because sections are global-ish,
				// we're not maintaining a list of created
				// sections to delete in case the import fails.
				$section = new Section();
				$section->setTitle($title);
				$section->setAbbrev($abbrev);
				$section->setJournalId($journal->getJournalId());
				// Kludge: We'll assume that there are less than
				// 10,000 sections; thus when the sections are
				// renumbered, this one should be last on the
				// list.
				$section->setSequence(10000);

				$section->setMetaIndexed(true);
				$section->setEditorRestricted(true);
				$section->setSectionId($sectionDao->insertSection($section));
				$sectionDao->resequenceSections($journal->getJournalId());
			}
		}

		// $section *must* now contain a valid section, whether it was
		// found amongst existing sections or created anew.
		$hasErrors = false;
		for ($index = 0; ($node = $sectionNode->getChildByName('article', $index)); $index++) {
			if (!NativeImportDom::handleArticleNode(&$journal, &$node, &$issue, &$section, &$article, &$publishedArticle, &$articleErrors, $user, $isCommandLine, $dependentItems)) {
				$errors = array_merge($errors, $articleErrors);
				$hasErrors = true;
			}
		}
		if ($hasErrors) return false;

		return true;
	}

	function handleArticleNode(&$journal, &$articleNode, &$issue, &$section, &$article, &$publishedArticle, &$errors, &$user, $isCommandLine, $dependentItems) {
		$errors = array();

		$publishedArticleDao = &DAORegistry::getDAO('PublishedArticleDAO');
		$articleDao = &DAORegistry::getDAO('ArticleDAO');

		$article = new Article();
		$article->setJournalId($journal->getJournalId());
		$article->setUserId($user->getUserId());
		$article->setSectionId($section->getSectionId());

		for ($index=0; ($node = $articleNode->getChildByName('title', $index)); $index++) {
			$locale = $node->getAttribute('locale');
			if ($locale == '' || $locale == Locale::getLocale()) {
				$article->setTitle($node->getValue());
			} elseif ($locale == $journal->getSetting('alternateLocale1')) {
				$article->setTitleAlt1($node->getValue());
			} elseif ($locale == $journal->getSetting('alternateLocale2')) {
				$article->setTitleAlt2($node->getValue());
			} else {
				$errors[] = array('plugins.importexport.native.import.error.articleTitleLocaleUnsupported', array('issueTitle' => $issue->getIssueIdentification(), 'sectionTitle' => $section->getTitle(), 'articleTitle' => $node->getValue(), 'locale' => $locale));
				return false;
			}
		}
		if ($article->getTitle() == '') {
			$errors[] = array('plugins.importexport.native.import.error.articleTitleMissing', array('issueTitle' => $issue->getIssueIdentification(), 'sectionTitle' => $section->getTitle()));
			return false;
		}

		for ($index=0; ($node = $articleNode->getChildByName('abstract', $index)); $index++) {
			$locale = $node->getAttribute('locale');
			if ($locale == '' || $locale == Locale::getLocale()) {
				$article->setAbstract($node->getValue());
			} elseif ($locale == $journal->getSetting('alternateLocale1')) {
				$article->setAbstractAlt1($node->getValue());
			} elseif ($locale == $journal->getSetting('alternateLocale2')) {
				$article->setAbstractAlt2($node->getValue());
			} else {
				$errors[] = array('plugins.importexport.native.import.error.articleAbstractLocaleUnsupported', array('issueTitle' => $issue->getIssueIdentification(), 'sectionTitle' => $section->getTitle(), 'articleTitle' => $article->getTitle(), 'locale' => $locale));
				return false;
			}
		}

		if (($indexingNode = $articleNode->getChildByName('indexing'))) {
			if (($node = $indexingNode->getChildByName('discipline'))) $article->setDiscipline($node->getValue());
			if (($node = $indexingNode->getChildByName('subject'))) $article->setSubject($node->getValue());
			if (($node = $indexingNode->getChildByName('subject_class'))) $article->setSubjectClass($node->getValue());
			if (($coverageNode = $indexingNode->getChildByName('coverage'))) {
				if (($node = $coverageNode->getChildByName('geographical'))) $article->setCoverageGeo($node->getValue());
				if (($node = $coverageNode->getChildByName('chronological'))) $article->setCoverageChron($node->getValue());
				if (($node = $coverageNode->getChildByName('sample'))) $article->setCoverageSample($node->getValue());
			}
		}

		$authors = array();
		for ($index=0; ($authorNode = $articleNode->getChildByName('author', $index)); $index++) {
			$author = new Author();
			if (($node = $authorNode->getChildByName('firstname'))) $author->setFirstName($node->getValue());
			if (($node = $authorNode->getChildByName('middlename'))) $author->setMiddleName($node->getValue());
			if (($node = $authorNode->getChildByName('lastname'))) $author->setLastName($node->getValue());
			if (($node = $authorNode->getChildByName('affiliation'))) $author->setAffiliation($node->getValue());
			if (($node = $authorNode->getChildByName('email'))) $author->setEmail($node->getValue());
			if (($node = $authorNode->getChildByName('biography'))) $author->setBiography($node->getValue());

			$author->setPrimaryContact($authorNode->getAttribute('primary_contact')==='true');
			$author->setSequence($index+1);

			$authors[] = $author;
		}
		$article->setAuthors($authors);

		$articleDao->insertArticle($article);
		$dependentItems[] = array('article', $article);

		$publishedArticle = new PublishedArticle();
		$publishedArticle->setArticleId($article->getArticleId());
		$publishedArticle->setIssueId($issue->getIssueId());
		
		if (($node = $articleNode->getChildByName('date_published'))) {
			$publishedDate = strtotime($node->getValue());
			if ($publishedDate === -1) {
				$errors[] = array('plugins.importexport.native.import.error.invalidDate', array('value' => $node->getValue()));
				return false;
			} else {
				$publishedArticle->setDatePublished($publishedDate);
			}
		}
		$node = $articleNode->getChildByName('open_access');
		$publishedArticle->setAccessStatus($node?'true':'false');

		// Kludge: This article should be last on the list. We resequence
		// the articles at the end of this code to make the seq meaningful.
		$publishedArticle->setSeq(100000);

		$publishedArticle->setViews(0);
		$publishedArticle->setPublicArticleId($articleNode->getAttribute('public_id'));

		$publishedArticle->setPubId($publishedArticleDao->insertPublishedArticle($publishedArticle));

		$publishedArticleDao->resequencePublishedArticles($section->getSectionId(), $issue->getIssueId());
		return true;
	}

	function cleanupFailure (&$dependentItems) {
		$issueDao = &DAORegistry::getDAO('IssueDAO');
		$articleDao = &DAORegistry::getDAO('ArticleDAO');

		foreach ($dependentItems as $dependentItem) {
			$type = array_shift($dependentItem);
			$object = array_shift($dependentItem);

			switch ($type) {
				case 'issue':
					$issueDao->deleteIssue($object);
					break;
				case 'article':
					$articleDao->deleteArticle($object);
					
			}
		}
	}
}

?>
