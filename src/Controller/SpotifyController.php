namespace App\Controller;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use SpotifyWebAPI\SpotifyWebAPI;
use SpotifyWebAPI\Session;
use Symfony\Component\HttpFoundation\Request;
use SpotifyWebAPI\SpotifyWebAPIAuthException;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class SpotifyController extends AbstractController
{
    private $api;
    private $session;
    private $cache;

    public function __construct(SpotifyWebApi $api, Session $session, CacheItemPoolInterface $cache)
    {
        $this->api = $api;
        $this->session = $session;
        $this->cache = $cache;
    }

    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        return $this->render('home/index.html.twig');
    }

    #[Route('/update', name: 'app_spotify_update_my_playlist')]
    public function updateMyPlaylist(Request $request, SessionInterface $session): Response
    {
        if (!$session->has('spotify_access_token')) {
            return $this->redirectToRoute('app_spotify_redirect');
        }

        $this->api->setAccessToken($session->get('spotify_access_token'));

        $top30 = $this->api->getMyTop('tracks', [
            'limit' => 40,
            'time_range' => 'short_term'
        ]);

        $top30TracksIds = array_map(function ($track) {
            return $track->id;
        }, $top30->items);

        $playlistId = $this->getParameter('SPOTIFY_PLAYLIST_ID');
        $this->api->replacePlaylistTracks($playlistId, $top30TracksIds);

        return $this->render('spotify/index.html.twig', [
            'tracks' => $this->api->getPlaylistTracks($playlistId),
        ]);
    }

    #[Route('/callback', name: 'app_spotify_callback')]
    public function callbackFromSpotify(Request $request, SessionInterface $session): Response
    {
        try {
            $this->session->requestAccessToken($request->query->get('code'));
        } catch (SpotifyWebAPIAuthException $e) {
            return new Response($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        $accessToken = $this->session->getAccessToken();

        // Store access token in the user's session
        $session->set('spotify_access_token', $accessToken);

        return $this->redirectToRoute('app_spotify_update_my_playlist');
    }

    #[Route('/redirect', name: 'app_spotify_redirect')]
    public function redirectToSpotify(): Response
    {
        $options = [
            'scope' => [
                'user-read-email',
                'user-read-private',
                'playlist-read-private',
                'playlist-modify-private',
                'playlist-modify-public',
                'user-top-read',
            ],
        ];

        return $this->redirect($this->session->getAuthorizeUrl($options));
    }
}
