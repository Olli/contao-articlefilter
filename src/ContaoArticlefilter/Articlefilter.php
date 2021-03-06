<?php

namespace ContaoArticlefilter;

/**
 * Class Articlefilter based on version of Stefan Gandlau <stefan@gandlau.net>
 *
 */
class Articlefilter extends \Controller
{

    /* ajax or form-request */
    protected $isAjax = false;

    /* pagination object */
    protected $pagination;

    /* data storage */
    protected $filterGroups         = [];
    protected $filterCriteria       = [];
    protected $searchFilterText     = [];
    protected $searchFilterCriteria = [];
    protected $arrPages             = [];

    protected $no_filter = true;
    protected $hasFilter = false;
    protected $afstype;
    public $sorting = 't2.sorting';
    protected $showAll = false;

    protected $articlefilter_groupbypage = false;
    protected $resultCount = 0;
    protected $results = [];

    protected $imgSize = null;

    public function __construct($rootid = false, $imgSize = null)
    {
        $this->imgSize = $imgSize;

        $this->Import('Database');
        $this->Import('Input');
        if (\Input::get('isAjax') == 1)
        {
            $this->isAjax = true;
        }
        $this->prepareFilter($rootid ? $rootid : 0);
    }

    public function run()
    {
        if ($this->showAll && !$this->hasFilter)
        {
            $res = \Database::getInstance()
                ->prepare('SELECT t1.*, t1.title atitle, t2.title ptitle from tl_article t1, tl_page t2 WHERE t1.articlefilter_enable=? AND t1.published=? AND t1.pid = t2.id ORDER BY '.$this->sorting)
                ->execute(1, 1);
            if ($res->numRows == 0)
            {
                $this->results     = [];
                $this->resultCount = 0;
                return;
            }
            $arrArticles = [];
            while ($res->next())
            {
                $row = $res->row();
                if ($this->articlefilter_groupbypage)
                {
                    if (!is_array($arrArticles[$res->ptitle]))
                    {
                        $arrArticles[$res->ptitle] = [];
                    }
                    $arrArticles[$res->ptitle][] = $this->createEntry($row);
                }
                else
                {
                    $arrArticles[] = $this->createEntry($row);
                }
            }

            $this->resultCount = count($arrArticles);
            $this->results     = $arrArticles;

            return;
        }
        if (!$this->hasFilter)
        {
            $this->resultCount = 0;
            $this->results     = [];
            return;
        }

        /* find all article */
        $res = \Database::getInstance()->prepare('SELECT t1.*, t1.title atitle, t2.title ptitle, t2.pageTitle pageTitle from tl_article t1, tl_page t2 WHERE t1.articlefilter_enable=? AND t1.published=? AND t1.pid = t2.id AND t1.pid IN ('.implode(',',
                $this->arrPages).') ORDER BY '.$this->sorting)->execute(1, 1);
        if ($res->numRows == 0)
        {
            return;
        }
        $arrArticles = [];
        while ($res->next())
        {
            $row = $res->row();
            $ac = deserialize($res->articlefilter_criteria);
            if (!is_array($ac))
            {
                continue;
            }
            if ($this->afstype == 'matchAny')
            {
                if (count(array_intersect($ac, $this->searchFilterCriteria)))
                {
                    if ($this->articlefilter_groupbypage)
                    {
                        if (!is_array($arrArticles[$res->ptitle]))
                        {
                            $arrArticles[$res->ptitle] = [];
                        }
                        $arrArticles[$res->ptitle][] = $this->createEntry($row);
                    }
                    else
                    {
                        $arrArticles[] = $this->createEntry($row);
                    }
                }
            }
            else
            {
                $allMatch = true;
                foreach ($this->searchFilterCriteria as $filter)
                {
                    if (!in_array($filter, $ac)) {
                        $allMatch = false;
                    }
                }

                if ($allMatch)
                {
                    if ($this->articlefilter_groupbypage)
                    {
                        if (!is_array($arrArticles[$res->ptitle]))
                        {
                            $arrArticles[$res->ptitle] = array();
                        }
                        $arrArticles[$res->ptitle][] = $this->createEntry($row);
                    }
                    else
                    {
                        $arrArticles[] = $this->createEntry($row);
                    }
                }
            }

        }

        $this->resultCount = count($arrArticles);
        $this->results     = $arrArticles;
    }

    private function createEntry($row)
    {
        $arrEntry = $row;
        if (($objArticle = \ArticleModel::findByIdOrAlias($row['id'])) !== null && ($objPid = $objArticle->getRelated('pid')) !== null)
        {
            $arrEntry['href'] = $this->generateFrontendUrl($objPid->row(), '/articles/'.((!\Config::get('disableAlias') && strlen($row['alias'])) ? $row['alias'] : $row['id']));
        }

        if ($row['addImage'] === '1' && strlen($row['singleSRC']) > 0)
        {
            $objFile = \FilesModel::findByUuid($row['singleSRC']);
            if ($objFile !== null)
            {
                $objTemp = new \stdClass();
                $arr     = ['singleSRC' => $objFile->path];

                if ($this->imgSize != '')
                {
                    $size = deserialize($this->imgSize);
                    if ($size[0] > 0 || $size[1] > 0 || is_numeric($size[2]))
                    {
                        $arr['size'] = $this->imgSize;
                    }
                }

                $this->addImageToTemplate($objTemp, $arr);

                $arrEntry['imagePath'] = $objFile->path;
                $arrEntry['picture']   = $objTemp->picture;
            }
        }
        return $arrEntry;
    }

    protected function prepareFilter($rootid)
    {
        $this->filterGroups   = $this->readFilterGroups();
        $this->filterCriteria = $this->readFilterCriteria();
        $this->arrPages       = $this->getPageIdsByPid($rootid);

    }

    protected function readFilterGroups()
    {
        $arrAllGroups = [];
        $res          = \Database::getInstance()->prepare('SELECT * from tl_articlefilter_groups')->execute();
        while ($res->next())
        {
            $arrAllGroups[$res->id] = $res->title;
        }
        return $arrAllGroups;
    }

    protected function readFilterCriteria()
    {
        $arrAllCriteria = [];
        $res            = \Database::getInstance()->prepare('SELECT * from tl_articlefilter_criteria')->execute();
        while ($res->next())
        {
            $arrAllCriteria[$res->id] = $res->title;
        }
        return $arrAllCriteria;
    }

    public function getPageIdsByPid($pid)
    {
        $res = \Database::getInstance()
            ->prepare('SELECT * from tl_page where pid=? AND published=? AND ( (start = "" || start < NOW()) && (stop = "" OR stop > NOW()))')
            ->execute($pid, 1);
        if ($res->numRows == 0)
        {
            return [];
        }
        while ($res->next())
        {
            $arrPages[] = $res->id;
            $subPages   = $this->getPageIdsByPid($res->id);
            if ($subPages != false)
            {
                $arrPages = array_merge($arrPages, $subPages);
            }
        }
        if (count($arrPages) > 0)
        {
            return $arrPages;
        }

        return falses;
    }

    public function __set($key, $value)
    {
        switch (strtolower($key))
        {
            case 'selectedfilter':
            {
                if (is_array($value) && count($value) > 0)
                {
                    $this->no_filter = false;
                    /* collect selected filter */
                    foreach ($value as $group => $criteria)
                    {
                        if (is_array($criteria))
                        {
                            foreach ($criteria as $c)
                            {
                                if (!strlen($c))
                                {
                                    continue;
                                }
                                $this->hasFilter = true;
                                $this->searchFilterText[] = [
                                    'group'     => $this->filterGroups[$group],
                                    'criteria'  => $this->filterCriteria[$c]
                                ];
                                $this->searchFilterCriteria[] = $c;
                            }
                        }
                        else
                        {
                            if ($criteria == '')
                            {
                                continue;
                            }
                            $this->hasFilter = true;
                            $this->searchFilterText[] = [
                                'group'     => $this->filterGroups[$group],
                                'criteria'  => $this->filterCriteria[$criteria]
                            ];
                            $this->searchFilterCriteria[] = $criteria;
                        }
                    }
                }
                else
                {
                    $this->resultCount = 0;
                }
            }
                break;

            case 'afstype':
                $this->afstype = $value;
                break;

            case 'sorting':
                $this->sorting = $value;
                break;

            case 'showall':
                $this->showAll = $value;
                break;

            case 'groupbypage':
                $this->articlefilter_groupbypage = $value;
                break;
        }
    }

    public function __get($key)
    {
        switch (strtolower($key))
        {
            case 'no_filter':
                return ($this->no_filter);
                break;

            case 'hasfilter':
                return ($this->hasFilter);
                break;

            case 'resultcount':
                return ($this->resultCount);
                break;

            case 'results':
                return ($this->results);
                break;

            case 'searchstrings':
                return ($this->searchFilterText);
                break;

            case 'groupbypage':
                return ($this->articlefilter_groupbypage);
                break;
        }
    }
}
