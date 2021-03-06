<?php

namespace App\Controller;

use App\Entity\Comment;
use App\Entity\Picture;
use App\Entity\Trick;
use App\Entity\User;
use App\Entity\Video;
use App\Form\CommentFormType;
use App\Form\TrickFormType;
use App\Repository\CommentRepository;
use App\Services\UploadService;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Security;
use Symfony\Contracts\Translation\TranslatorInterface;


/**
 * @Route("/tricks", name="trick")
 */
class TrickController extends AbstractController
{

  private $manager;
  private $translator;

    public function __construct(EntityManagerInterface $manager, TranslatorInterface $translator) {
      $this->manager = $manager;
      $this->translator = $translator;
    }

	/**
	 * @Route("/create_trick/", name="_create")
	 * @Route("/edit-{slug}", options={"expose"=true},name="_edit")
	 * @param Request $request
	 * @param Trick|null $trick
	 * @param UploadService $upload
	 * @return Response
	 */
    public function editTrick(Request $request, Trick $trick = null, UploadService $upload)
    {
        if(!$trick) {
          $trick = new Trick();
        }

        $form = $this->createForm(TrickFormType::class, $trick);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

          $newPicture = $upload->uploadFile($form['picture']->getData());
					$upload = new UploadService('upload_directory');
					
          foreach ($form['pictures']->getData() as $keyPicture => $pic) {
            $picture = new Picture();

            $newPictures = $upload->uploadFile($pic);
            $picture->setFileName($newPictures);

            $trick->addPicture($picture);
          }
          $trick->setPicture($newPicture);
          $trick->setDate(new \DateTime('NOW'));
          $trick->setUser($this->getUser());
          $this->manager->persist($trick);
          $this->manager->flush();

					$this->addFlash(
						'info',
						$this->translator->trans('Trick_Creation_Success') . ' : ' . $trick->getName()
					);

          return $this->redirectToRoute('home');
        }
        return $this->render('tricks/edit_trick.html.twig', [
          'form' => $form->createView(),
          'trick' => ($trick) ? $trick : null
        ]);
    }

	/**
	 * @Route("/{slug}", options={"expose"=true},name="_detail")
	 * @param Trick $trick
	 * @param Request $request
	 * @param Security $security
	 * @param PaginatorInterface $paginator
	 * @return Response
	 */
    public function getDetailsTrick(Trick $trick, Request $request, Security $security, PaginatorInterface $paginator) {

      $comment = new Comment();

      $formComment = $this->createForm(CommentFormType::class, $comment);
      $formComment->handleRequest($request);

      $videos = [];

      foreach ($trick->getVideos() as $video) {

        if(preg_match("/^(?:http(?:s)?:\/\/)?(?:www\.)?(?:m\.)?(?:youtu\.be\/|youtube\.com\/(?:(?:watch)?\?(?:.*&)?v(?:i)?=|(?:embed|v|vi|user)\/))([^\?&\"'>]+)/", $video->getEmbed(), $matches)) {

          $videos[] = '<iframe title="video figure" class="embed-responsive-item" src="https://www.youtube.com/embed/' . $matches[1] . '?rel=0" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>';

        } elseif (preg_match("/^(?:http(?:s)?:\/\/)?(?:www\.)?(?:m\.)?(?:dai\.ly\/|dailymotion\.com\/(?:(?:embed)\/))([^\?&\"'>]+)/", $video->getEmbed(), $matches)){

          $videos[] = '<iframe title="video figure" class="embed-responsive-item" src="https://www.dailymotion.com/embed/' .$matches[1].'" allowfullscreen></iframe>';
        }
      }

      if($formComment->isSubmitted() && $formComment->isValid() && !is_null($security->getUser())) {
      	$comment = $formComment->getData();

      	$comment->setDate(new \DateTime('NOW'));
      	$comment->setTrick($trick);
      	$comment->setUser($security->getUser());

      	$this->manager->persist($comment);
      	$this->manager->flush();
      }

      if(!$trick->getComments()->isEmpty()) {
      	$listComments = $paginator->paginate(
      		$trick->getComments(),
					1,
					4
				);
      	$nbListComments = $listComments->getTotalItemCount();
			} else {
      	$listComments = null;
      	$nbListComments = null;
			}

      return $this->render('tricks/detail-trick.html.twig', [
        'trick' => $trick,
        'videos' => $videos,
        'comments' => $listComments,
        'nbComments' => $nbListComments,
        'formComment' => $formComment->createView()
      ]);
    }

	/**
	 * @Route("/{slug}/load-more-comment-{page}", name="_load_more_comment")
	 * @param Trick $trick
	 * @param PaginatorInterface $paginator
	 * @param Request $request
	 * @return Response
	 */
    public function loadMoreComments(Trick $trick, PaginatorInterface $paginator, Request $request, $page) {
			if($request->isXmlHttpRequest()) {

				$listComments = $paginator->paginate(
					$trick->getComments(),
					$page,
					4
				);
				return $this->render('assets/card-comment.html.twig', [
					'comments' => $listComments,
					'nbComments' => $listComments->getTotalItemCount(),
					'trick' => $trick
				]);
			}
		}

	/**
	 * @Route("/{slug}/delete-this-trick", options={"expose"=true}, name="_delete")
	 * @param Trick $trick
	 * @return \Symfony\Component\HttpFoundation\RedirectResponse
	 */
    public function deleteTrick(Trick $trick) {

			if($this->isGranted('IS_AUTHENTICATED_FULLY')) {
				if($this->isGranted('ROLE_ADMIN') || $trick->getUser()->getUsername() == $this->getUser()->getUsername()) {
					$name = $trick->getName();
					$this->manager->remove($trick);
					$this->manager->flush();

					$this->addFlash(
						'info',
						$this->translator->trans('Trick_Delete_Success') . ' : ' . $trick->getName()
					);

				}
			}
			return $this->redirectToRoute('home');
    }

	/**
	 * @Route("/{slug}/delete-comment-{id}", name="_comment_delete")
	 * @param CommentRepository $commentRepository
	 * @param $slug
	 * @param $id
	 * @return \Symfony\Component\HttpFoundation\RedirectResponse
	 */
    public function deleteComment(CommentRepository $commentRepository, $slug, $id) {
			$comment = $commentRepository->find($id);
			if($this->isGranted('IS_AUTHENTICATED_FULLY')) {

				if ($this->isGranted('ROLE_ADMIN') || $comment->getUser()->getUsername() == $this->getUser()->getUsername()) {

					$this->manager->remove($comment);
					$this->manager->flush();

					$this->addFlash(
						'info',
						$this->translator->trans('Comment_Delete_Success')
					);

					return $this->redirectToRoute('home');
				}
			}
			return $this->redirectToRoute('trick_detail', [
				'slug' => $slug
			]);
		}

	/**
	 * @Route("/{slug}/delete-user-{id}", name="_user_delete")
	 * @param User $user
	 * @param $slug
	 * @return \Symfony\Component\HttpFoundation\RedirectResponse
	 */
	public function deleteUser(User $user, $slug) {

		if($this->isGranted('ROLE_ADMIN')) {
			$this->manager->remove($user);
			$this->manager->flush();

			$this->addFlash(
				'info',
				$this->translator->trans('User_Delete_Success')
			);
		}
		return $this->redirectToRoute('home');
	}

	/**
	 *@Route("/{slug}/delete-video-{id}", name="_video_delete")
	 */
	public function deleteVideo(Video $video, $slug) {
		$this->manager->remove($video);
		$this->manager->flush();

		return $this->redirectToRoute('trick_detail',[
			'slug' => $slug
		]);
	}
}
