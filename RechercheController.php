<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use Psr\Log\LoggerInterface;

use Doctrine\ORM\EntityManagerInterface;

use ApaiIO\Configuration\GenericConfiguration;
use ApaiIO\ApaiIO;
use ApaiIO\Operations\Search;
use ApaiIO\Request\GuzzleRequestWithoutKeys;
use GuzzleHttp\Client;

use DeezerAPI\Search as DeezerSearch ;

use App\Entity\Catalogue\Article;
use App\Entity\Catalogue\Livre;
use App\Entity\Catalogue\Musique;
use App\Entity\Catalogue\Piste;

class RechercheController extends AbstractController
{
	private $entityManager;
	private $logger;
	
	public function __construct(EntityManagerInterface $entityManager, LoggerInterface $logger)  {
		$this->entityManager = $entityManager;
		$this->logger = $logger;
	}
	
    /**
     * @Route("/afficheRecherche", name="afficheRecherche")
     */
    public function afficheRechercheAction(Request $request)
    {
		$this->initAmazon() ;
		$query = $this->entityManager->createQuery("SELECT a FROM App\Entity\Catalogue\Article a");
		$articles = $query->getResult();
		return $this->render('recherche.html.twig', [
            'articles' => $articles,
        ]);
    }
	
	
    /**
     * @Route("/afficheRechercheParMotCle", name="afficheRechercheParMotCle")
     */
    public function afficheRechercheParMotCleAction(Request $request)
    {
		$this->initAmazon() ;
		//$query = $this->entityManager->createQuery("SELECT a FROM App\Entity\Catalogue\Article a "
		//										  ." where a.titre like :motCle");
		//$query->setParameter("motCle", "%".$request->query->get("motCle")."%") ;
		$query = $this->entityManager->createQuery("SELECT a FROM App\Entity\Catalogue\Article a "
												  ." where a.titre like '%".addslashes($request->query->get("motCle"))."%'");
		$articles = $query->getResult();
		return $this->render('recherche.html.twig', [
            'articles' => $articles,
        ]);
    }
	
	private function initAmazon() {
		if (count($this->entityManager->getRepository("App\Entity\Catalogue\Article")->findAll()) == 0) {
			$conf = new GenericConfiguration();
			$client = new Client();
			$request = new GuzzleRequestWithoutKeys($client);

			try {
				/*$conf
					->setCountry('de')
					->setAccessKey(AWS_API_KEY)
					->setSecretKey(AWS_API_SECRET_KEY)
					->setAssociateTag(AWS_ASSOCIATE_TAG);*/
				$conf
					->setCountry('fr')
					->setRequest($request) ;
			} catch (\Exception $e) {
				echo $e->getMessage();
			}
			$apaiIO = new ApaiIO($conf);

			$search = new Search();
			$search->setCategory('Music');

/* 			$form->handleRequest($request);
			if ($form->isSubmitted() && $form->isValid()) {
				dd($form->getData());
			} */
			$keywords = 'U2';
			
			//$search->setCategory('Books');
			//$keywords = 'Henning Mankell' ;
			
			$search->setKeywords($keywords);
			
			$search->setResponseGroup(array('Offers','ItemAttributes','Images'));

			$formattedResponse = $apaiIO->runOperation($search);
			file_put_contents("amazonResponse.xml",$formattedResponse) ;
			$xml = simplexml_load_string($formattedResponse);
			if ($xml !== false) {
				foreach ($xml->children() as $child_1) {
					if ($child_1->getName() === "Items") {
						foreach ($child_1->children() as $child_2) {
							if ($child_2->getName() === "Item") {
								if ($child_2->ItemAttributes->ProductGroup->__toString() === "Book") {
									$entityLivre = new Livre();
									$entityLivre->setRefArticle($child_2->ASIN);
									$entityLivre->setTitre($child_2->ItemAttributes->Title);
									$entityLivre->setAuteur($child_2->ItemAttributes->Author);
									$entityLivre->setISBN($child_2->ItemAttributes->ISBN);
									$entityLivre->setPrix($child_2->OfferSummary->LowestNewPrice->Amount/100.0); 
									$entityLivre->setDisponibilite(1);
									$entityLivre->setImage($child_2->LargeImage->URL);
									$this->entityManager->persist($entityLivre);
									$this->entityManager->flush();
								}
								if ($child_2->ItemAttributes->ProductGroup->__toString() === "Music") {
									$entityMusique = new Musique();
									$entityMusique->setRefArticle($child_2->ASIN);
									$entityMusique->setTitre($child_2->ItemAttributes->Title);
									$entityMusique->setArtiste($child_2->ItemAttributes->Artist);
									$entityMusique->setDateDeParution($child_2->ItemAttributes->PublicationDate);
									$entityMusique->setPrix($child_2->OfferSummary->LowestNewPrice->Amount/100.0); 
									$entityMusique->setDisponibilite(1);
									$entityMusique->setImage($child_2->LargeImage->URL);
									if (!isset($albums)) {
										$deezerSearch = new DeezerSearch($keywords);
										$artistes = $deezerSearch->searchArtist() ;
										$albums = $deezerSearch->searchAlbumsByArtist($artistes[0]->getId()) ;
									}
									$j = 0 ;
									$sortir = ($j==count($albums)) ;
									$albumTrouve = false ;
									while (!$sortir) {
										$titreDeezer = str_replace(" ","",mb_strtolower($albums[$j]->title)) ;
										$titreAmazon = str_replace(" ","",mb_strtolower($entityMusique->getTitre())) ;
										$titreDeezer = str_replace("-","",$titreDeezer) ;
										$titreAmazon = str_replace("-","",$titreAmazon) ;
                                        $albumTrouve = ($titreDeezer == $titreAmazon) ;
                                        if (mb_strlen($titreAmazon) > mb_strlen($titreDeezer))
                                            $albumTrouve = $albumTrouve || (mb_strpos($titreAmazon, $titreDeezer) !== false) ;
                                         if (mb_strlen($titreDeezer) > mb_strlen($titreAmazon))
                                            $albumTrouve = $albumTrouve || (mb_strpos($titreDeezer, $titreAmazon) !== false) ;
										$j++ ;
										$sortir = $albumTrouve || ($j==count($albums)) ;
									}
									if ($albumTrouve) {
										$tracks = $deezerSearch->searchTracksByAlbum($albums[$j-1]->getId()) ;
										foreach ($tracks as $track) {
											$entityPiste = new Piste();
											$entityPiste->setTitre($track->title);
											$entityPiste->setUrl($track->preview);
											$this->entityManager->persist($entityPiste);
											$this->entityManager->flush();
											$entityMusique->addPiste($entityPiste) ;
										}
									}
									$this->entityManager->persist($entityMusique);
									$this->entityManager->flush();
								}
							}
						}
					}
				}
			//}
			//else {
				$entityLivre = new Livre();
				$entityLivre->setRefArticle("1141555677821");
				$entityLivre->setTitre("Le seigneur des anneaux");
				$entityLivre->setAuteur("J.R.R. TOLKIEN");
				$entityLivre->setISBN("2075134049");
				$entityLivre->setNbPages(736);
				$entityLivre->setDateDeParution("03/10/19");
				$entityLivre->setPrix("8.90");
				$entityLivre->setDisponibilite(1);
				$entityLivre->setImage("/images/51O0yBHs+OL._SL500_.jpg");
				$this->entityManager->persist($entityLivre);
				$this->entityManager->flush();
				$entityLivre = new Livre();
				$entityLivre->setRefArticle("1141555897821");
				$entityLivre->setTitre("Un paradis trompeur");
				$entityLivre->setAuteur("Henning Mankell");
				$entityLivre->setISBN("275784797X");
				$entityLivre->setNbPages(400);
				$entityLivre->setDateDeParution("09/10/14");
				$entityLivre->setPrix("6.80");
				$entityLivre->setDisponibilite(1);
				$entityLivre->setImage("/images/41kQXtYSFPL._SL500_.jpg");
				$this->entityManager->persist($entityLivre);
				$this->entityManager->flush();
				$entityLivre = new Livre();
				$entityLivre->setRefArticle("1141556299459");
				$entityLivre->setTitre("Dôme tome 1");
				$entityLivre->setAuteur("Stephen King");
				$entityLivre->setISBN("2212110685");
				$entityLivre->setNbPages(840);
				$entityLivre->setDateDeParution("06/03/13");
				$entityLivre->setPrix("8.90");
				$entityLivre->setDisponibilite(1);
				$entityLivre->setImage("/images/41ICy6+-LdL._SL500_.jpg");
				$this->entityManager->persist($entityLivre);
				$this->entityManager->flush();
			}
		}
	}


    /**
     * @Route("/test", name="test")
     */

	public function test(Request $request)
    {	
		$motCle = $request->query->get("motCle");
		$categorie = $request->query->get("categorie");

		//switch pour modifier la valeur que l'on récupère pour qu'elle puisse être reconnue par amazon
		switch($categorie){
			case "Chaussures":
				$categorie = "Shoes";
				break;
			case "Electronique":
				$categorie = "Electronics";
				break;
			case "Jeux Vidéos":
				$categorie = "VideoGames";
				break;
			case "DVD":
				$categorie = "DVD";
				break;
			case "Vetements Homme":
				$categorie = "FashionMen";
				break;
			case "Vetements Femme":
				$categorie = "FashionWomen";
				break;
			case "Vetements Enfant":
				$categorie = "FashionBaby";
				break;
			case "Musique":
				$categorie = "Music";
				break;
			case "Livres":
				$categorie = "Books";
				break;
			case "Vetements Fille":
				$categorie = "FashionGirls";
				break;
			case "Vetements Garcon":
				$categorie = "FashionBoys";
				break;
			case "Automobile":
				$categorie = "Automotive";
				break;
			default:
				$categorie = null;
		}

		$this->deleteData();
		$this->initAmazonTest($motCle, $categorie);
		$query = $this->entityManager->createQuery("SELECT a FROM App\Entity\Catalogue\Article a "." where a.titre like '%".addslashes($request->query->get("motCle"))."%'");
		$articles = $query->getResult();
		return $this->render('recherche.html.twig', [
            'articles' => $articles,
        ]);
    }

	private function deleteData(){
		$query = $this->entityManager->createQuery("SELECT a FROM App\Entity\Catalogue\Article a ");
		$articles = $query->getResult();
		foreach($articles as $article){
			$this->entityManager->remove($article);
			$this->entityManager->flush();
		}
	}
	
	private function initAmazonTest($keywords, $categorie) {
		if (count($this->entityManager->getRepository("App\Entity\Catalogue\Article")->findAll()) == 0) {
			$conf = new GenericConfiguration();
			$client = new Client();
			$request = new GuzzleRequestWithoutKeys($client);

			try {
				/*$conf
					->setCountry('de')
					->setAccessKey(AWS_API_KEY)
					->setSecretKey(AWS_API_SECRET_KEY)
					->setAssociateTag(AWS_ASSOCIATE_TAG);*/
				$conf
					->setCountry('fr')
					->setRequest($request) ;
			} catch (\Exception $e) {
				echo $e->getMessage();
			}
			$apaiIO = new ApaiIO($conf);

			$search = new Search();

			$search->setCategory($categorie);

/* 			$form->handleRequest($request);
			if ($form->isSubmitted() && $form->isValid()) {
				dd($form->getData());
			} */
			//$keywords = 'U2';
			
			//$search->setCategory('Books');
			//$keywords = 'Henning Mankell' ;
			
			$search->setKeywords($keywords);
			
			$search->setResponseGroup(array('Offers','ItemAttributes','Images'));

			$formattedResponse = $apaiIO->runOperation($search);
			file_put_contents("amazonResponse.xml",$formattedResponse) ;
			$xml = simplexml_load_string($formattedResponse);
			if ($xml !== false) {
				foreach ($xml->children() as $child_1) {
					if ($child_1->getName() === "Items") {
						foreach ($child_1->children() as $child_2) {
							if ($child_2->getName() === "Item") {
								if ($child_2->ItemAttributes->ProductGroup->__toString() === "Book") {
									$entityLivre = new Livre();
									$allclass = get_class_methods($entityLivre);
									//var_dump($allclass);
									//$entityLivre->setRefArticle($child_2->ASIN);
									$entityLivre->setRefArticle(uniqid());
									$entityLivre->setTitre($child_2->ItemAttributes->Title);
									$entityLivre->setAuteur($child_2->ItemAttributes->Author);
									$entityLivre->setISBN($child_2->ItemAttributes->ISBN);
									$entityLivre->setPrix($child_2->OfferSummary->LowestNewPrice->Amount/100.0); 
									$entityLivre->setDisponibilite(1);
									$entityLivre->setImage($child_2->LargeImage->URL);
									$this->entityManager->persist($entityLivre);
									$this->entityManager->flush();
								}
								if ($child_2->ItemAttributes->ProductGroup->__toString() === "Music") {
									$entityMusique = new Musique();
									$entityMusique->setRefArticle($child_2->ASIN);
									$entityMusique->setTitre($child_2->ItemAttributes->Title);
									$entityMusique->setArtiste($child_2->ItemAttributes->Artist);
									$entityMusique->setDateDeParution($child_2->ItemAttributes->PublicationDate);
									$entityMusique->setPrix($child_2->OfferSummary->LowestNewPrice->Amount/100.0); 
									$entityMusique->setDisponibilite(1);
									$entityMusique->setImage($child_2->LargeImage->URL);
									if (!isset($albums)) {
										$deezerSearch = new DeezerSearch($keywords);
										$artistes = $deezerSearch->searchArtist() ;
										$albums = $deezerSearch->searchAlbumsByArtist($artistes[0]->getId()) ;
									}
									$j = 0 ;
									$sortir = ($j==count($albums)) ;
									$albumTrouve = false ;
									while (!$sortir) {
										$titreDeezer = str_replace(" ","",mb_strtolower($albums[$j]->title)) ;
										$titreAmazon = str_replace(" ","",mb_strtolower($entityMusique->getTitre())) ;
										$titreDeezer = str_replace("-","",$titreDeezer) ;
										$titreAmazon = str_replace("-","",$titreAmazon) ;
                                        $albumTrouve = ($titreDeezer == $titreAmazon) ;
                                        if (mb_strlen($titreAmazon) > mb_strlen($titreDeezer))
                                            $albumTrouve = $albumTrouve || (mb_strpos($titreAmazon, $titreDeezer) !== false) ;
                                         if (mb_strlen($titreDeezer) > mb_strlen($titreAmazon))
                                            $albumTrouve = $albumTrouve || (mb_strpos($titreDeezer, $titreAmazon) !== false) ;
										$j++ ;
										$sortir = $albumTrouve || ($j==count($albums)) ;
									}
									if ($albumTrouve) {
										$tracks = $deezerSearch->searchTracksByAlbum($albums[$j-1]->getId()) ;
										foreach ($tracks as $track) {
											$entityPiste = new Piste();
											$entityPiste->setTitre($track->title);
											$entityPiste->setUrl($track->preview);
											$this->entityManager->persist($entityPiste);
											$this->entityManager->flush();
											$entityMusique->addPiste($entityPiste) ;
										}
									}
									$this->entityManager->persist($entityMusique);
									$this->entityManager->flush();
								}
							}
						}
					}
				}
			//}
			//else {
				$entityLivre = new Livre();
				$entityLivre->setRefArticle("1141555677821");
				$entityLivre->setTitre("Le seigneur des anneaux");
				$entityLivre->setAuteur("J.R.R. TOLKIEN");
				$entityLivre->setISBN("2075134049");
				$entityLivre->setNbPages(736);
				$entityLivre->setDateDeParution("03/10/19");
				$entityLivre->setPrix("8.90");
				$entityLivre->setDisponibilite(1);
				$entityLivre->setImage("/images/51O0yBHs+OL._SL500_.jpg");
				$this->entityManager->persist($entityLivre);
				$this->entityManager->flush();
				$entityLivre = new Livre();
				$entityLivre->setRefArticle("1141555897821");
				$entityLivre->setTitre("Un paradis trompeur");
				$entityLivre->setAuteur("Henning Mankell");
				$entityLivre->setISBN("275784797X");
				$entityLivre->setNbPages(400);
				$entityLivre->setDateDeParution("09/10/14");
				$entityLivre->setPrix("6.80");
				$entityLivre->setDisponibilite(1);
				$entityLivre->setImage("/images/41kQXtYSFPL._SL500_.jpg");
				$this->entityManager->persist($entityLivre);
				$this->entityManager->flush();
				$entityLivre = new Livre();
				$entityLivre->setRefArticle("1141556299459");
				$entityLivre->setTitre("Dôme tome 1");
				$entityLivre->setAuteur("Stephen King");
				$entityLivre->setISBN("2212110685");
				$entityLivre->setNbPages(840);
				$entityLivre->setDateDeParution("06/03/13");
				$entityLivre->setPrix("8.90");
				$entityLivre->setDisponibilite(1);
				$entityLivre->setImage("/images/41ICy6+-LdL._SL500_.jpg");
				$this->entityManager->persist($entityLivre);
				$this->entityManager->flush();
			}
		}
	}

	/**
     * @Route("/tests", name="tests")
     */

	public function tests(Request $request){
		$response = new Response();
		if (file_exists('amazonResponse.xml')) {
			$xml = simplexml_load_file('amazonResponse.xml');
			foreach($xml->children() as $param){
				if($param->getName() === "Items"){
					$i = 0;
					foreach($param->children() as $items){
						var_dump($items);
						$i++;
						if($items->getName() === "Item"){
							$array = [];
							array_keys($array, $items->ItemAttributes);
							var_dump($array);
						}
					}
				}
			}
			return $response->setContent('');
		} else {
			exit('Echec lors de l\'ouverture du fichier amazonResponse.xml.');
		}
	}
}