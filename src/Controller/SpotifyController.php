<?php

namespace App\Controller;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use SpotifyWebAPI\SpotifyWebAPI;
use SpotifyWebAPI\Session;
use Symfony\Component\HttpFoundation\Request;
use SpotifyWebAPI\SpotifyWebAPIAuthException;

class SpotifyController extends AbstractController
{
    public function __construct(private readonly SpotifyWebApi $api, private readonly Session $session, Private readonly CacheItemPoolInterface $cache) {}

    // #[Route('/spotify', name: 'app_spotify')]
    // public function index(): Response
    // {
    //     $search = $this->api->search('Thriller', 'album');
    //     dd($search);
    //     return $this->render('spotify/index.html.twig', [
    //         'controller_name' => 'SpotifyController',
    //     ]);
    // }
    #[Route('/', name: 'app_spotify_update_my_playlist')]
    public function updateMyPlaylist(Request $request): Response
    {
        # Verifier que dans un cache on a access token pour ne pas rediriger a chaque fois, sinon on va devoir aller le chercher
        if (!$this->cache->hasItem('spotify_access_token')) {
            return $this->redirectToRoute('app_spotify_redirect');
        }
        # La on va voir que l'access token est dans le cache
        // dd($this->cache->getItem('spotify_access_token')->get());
        $this->api->setAccessToken($this->cache->getItem('spotify_access_token')->get());

        # Voir toutes les playlits
        // dd($this->api->getMyPlaylists());

        $top30 = $this->api->getMyTop('tracks', [
            'limit' => 40,
            'time_range' => 'short_term'
        ]);
        // dd($top30);
        $top30TracksIds = array_map(function ($track) {
            return $track->id;
        }, $top30->items);
        // dd($top30TracksIds);
        $playlistId = $this->getParameter('SPOTIFY_PLAYLIST_ID');
        $this->api->replacePlaylistTracks($playlistId, $top30TracksIds);
        // dd('done');
        return $this->render('spotify/index.html.twig', [
            'tracks' => $this->api->getPlaylistTracks($playlistId),
        ]);
    }
    #[Route('/callback', name: 'app_spotify_callback')]
    public function callbackFromSpotify(Request $request): Response
    {
        try {
            $this->session->requestAccessToken($request->query->get('code'));
        } catch (SpotifyWebAPIAuthException $e) {
            return new Response($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }
        
        # La on va mettre l'access token dans un cache
        # on va lui dire si un cacheItem  n'existe pas le crée au passage 
        $cacheItem = $this->cache->getItem('spotify_access_token');
        $cacheItem->set($this->session->getAccessToken());
        $cacheItem->expiresAfter(3600);
        $this->cache->save($cacheItem);

        $this->session->getAccessToken();
        return $this->redirectToRoute('app_spotify_update_my_playlist');

        // l'objet $me est les informations du compte qui va utiliser l'api
        // $me = $this->api->me();
    }
    #[Route('/redirect', name: 'app_spotify_redirect')]
    public function redirectToSpotify(): Response
    {
        $options = [
            'scope' => [
                'user-read-email',
                # Read access to user’s subscription details (type of user account).
                'user-read-private',
                # Read access to user's private playlists.
                'playlist-read-private',
                # Write access to a user's private playlists.
                'playlist-modify-private',
                # Write access to a user's public playlists.
                'playlist-modify-public',
                # Read access to a user's top artists and tracks.
                'user-top-read',
            ],
        ];

        return $this->redirect($this->session->getAuthorizeUrl($options));
    }
}
