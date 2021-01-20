<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;
use App\Service\FlashMessageService;
use App\Http\Requests\EventRequest;
use Carbon\Carbon;
use App\Models\Team;
use App\Models\Event;
use App\Models\Member;
use App\Models\MainGame;
use App\Models\Result;

class TournamentController extends Controller
{
    public function __construct()
    {
       $this->middleware('auth');
    }

    public function index(Request $request)
    {
        $event = $request->session()->get('event');
        if (!$event) {
            return redirect()->route('event.index');
        }
        $member = null;

        // 参加している対戦表のチェック
        if (Auth::user()->role == config('user.role.member')) {
            $member = Member::join('teams', 'teams.id', 'members.team_id')
            ->where('event_id', $event)
            ->where('user_id', Auth::id())->first();
        }
        if ($member && $request->sheet == '') {
            $selectSheet = $member->team->sheet;
            $selectBlock = $member->team->block;
        } else {
            $selectSheet = $request->sheet;
            $selectBlock = $request->block;
        }
        if (!$selectBlock) {
            $selectBlock = 'A';
        }

        $blocks = Team::getBlocks($event);
        $sheets = Team::getSheets($event, $selectBlock);

        if (count($blocks) < 1) {
            $request->session()->forget('block');
            FlashMessageService::error('対戦表はまだ作成されてません');
            return redirect()->route('event.detail', ['id' => session('event')]);
        }

        $request->session()->put('block', $selectBlock);
        if ($selectSheet == 'all' || $selectSheet == '') {
            $results = Team::where('event_id', $event)
            ->where('block', $selectBlock)
            ->orderBy('sheet')
            ->orderBy('number')
            ->get();

            $games = Result::where('event_id', $event)
            ->where('block', $selectBlock)
            ->get();
        } else {
            $results = Team::where('event_id', $event)
            ->where('block', $selectBlock)
            ->where('sheet', $selectSheet)
            ->orderBy('number')
            ->get();

            $games = Result::where('event_id', $event)
            ->where('block', $selectBlock)
            ->where('sheet', $selectSheet)
            ->get();
        }
        $teams = array();
        $vs = array();
        foreach ($results as $key => $value) {
            $teams[$value->sheet][$value->number]['id'] = $value->id;
            $teams[$value->sheet][$value->number]['number'] = $value->number;
            $teams[$value->sheet][$value->number]['name'] = $value->name;
            $teams[$value->sheet][$value->number]['abstention'] = $value->abstention;
            $teams[$value->sheet][$value->number]['win_num'] = 0;
            $teams[$value->sheet][$value->number]['win_total'] = 0;
            $teams[$value->sheet][$value->number]['lose_total'] = 0;
            $teams[$value->sheet][$value->number]['created_at'] = $value->created_at;

            $vs[$value->id] = array();
            $team_id = $value->id;
            $blockTeam = Team::where('event_id', $event)
            ->where('block', $selectBlock)
            ->where('sheet', $value->sheet)
            ->where('id', '<>', $team_id)
            ->orderBy('number')
            ->get();
            foreach ($blockTeam as $key => $v) {
                $vs[$team_id][$key]['name'] = $v->name;
                $win = Result::where('win_team_id', $team_id)
                ->where('event_id', $event)
                ->where('lose_team_id', $v->id)
                ->first();
                $lose = Result::where('lose_team_id', $team_id)
                ->where('event_id', $event)
                ->where('win_team_id', $v->id)
                ->first();
                if ($win || $lose) {
                    if ($win) {
                        $vs[$team_id][$key]['win'] = true;
                        $vs[$team_id][$key]['score'] = $win->win_score.'-'.$win->lose_score;
                        $teams[$value->sheet][$value->number]['win_num'] += 3;
                        $teams[$value->sheet][$value->number]['win_total'] += $win->win_score;
                        $teams[$value->sheet][$value->number]['lose_total'] += $win->lose_score;
                    } else if($lose) {
                        $vs[$team_id][$key]['win'] = false;
                        $vs[$team_id][$key]['score'] = $lose->lose_score.'-'.$lose->win_score;
                        $teams[$value->sheet][$value->number]['win_num'] += $lose->lose_score;
                        $teams[$value->sheet][$value->number]['win_total'] += $lose->lose_score;
                        $teams[$value->sheet][$value->number]['lose_total'] += $lose->win_score;
                    }
                } else {
                    $vs[$team_id][$key]['win'] = false;
                    $vs[$team_id][$key]['score'] = '?';
                }
            }
            $winScore = $teams[$value->sheet][$value->number]['win_total'];
            $loseScore = $teams[$value->sheet][$value->number]['lose_total'];
            if ($winScore == 0) {
                $teams[$value->sheet][$value->number]['percent'] = 0;
            } else {
                $teams[$value->sheet][$value->number]['percent'] = round($winScore / ($winScore + $loseScore) * 100, 1);
            }
        }

        $ranks = $teams;
        // 並び替え・一位確定未実装
        if ($selectSheet == 'all' || $selectSheet == '') {
          foreach ($sheets as $key => $value) {
            $keys = array_column($ranks[$value->sheet], 'win_num');
            array_multisort($keys, SORT_DESC, $ranks[$value->sheet]);
          }
        } else {
            $keys = array_column($ranks[$selectSheet], 'win_num');
            array_multisort($keys, SORT_DESC, $ranks[$selectSheet]);
        }

        $scores = array();
        foreach (config('game.pre') as $key => $val) {
          foreach ($val as $k => $conf) {
              foreach ($games as $key => $value) {
                  if ($value->winteam->number == $conf[0] && $value->loseteam->number == $conf[1] ||
                  $value->winteam->number == $conf[1] && $value->loseteam->number == $conf[0]) {
                      if ($conf[0] == 1) {
                          $num = 0;
                      } else {
                          $num = 1;
                      }
                      $scores[$value->block][$value->sheet][$value->turn][$num]['win'] = $value->winteam->number;
                      $scores[$value->block][$value->sheet][$value->turn][$num][$value->win_team_id]['score'] = $value->win_score;
                      $scores[$value->block][$value->sheet][$value->turn][$num][$value->lose_team_id]['score'] = $value->lose_score;
                  }
              }

          }
        }
        return view('tournament.index',
        compact('selectBlock', 'selectSheet', 'sheets', 'teams', 'blocks', 'member', 'vs', 'ranks', 'scores'));
    }

    public function make(Request $request)
    {
        $event_id = $request->session()->get('event');
        if (!$event_id) {
            $request->session()->forget('block');
            return redirect()->route('event.index');
        }
        $event = Event::find($event_id);
        $sheetNum = 16;
        $teamBySheet = 4;

        $teams = Team::getAllTeam($event_id);
        $targetTeamCnt = count($teams);
        $makeBlockCnt = ceil(count($teams) / ($sheetNum * $teamBySheet));
        return view('tournament.make', compact('targetTeamCnt', 'makeBlockCnt'));
    }

    public function makeStore(Request $request)
    {
        try {
            $event_id = $request->session()->get('event');
            $event = Event::find($event_id);
            Team::resetAllTeam($event_id);
            $teams = Team::getAllTeam($event_id, $request->order_rule);
            $sheetNum = 16;
            $teamBySheet = 4;
            // ブロック数
            $blockNum = ceil(count($teams) / ($sheetNum * $teamBySheet));
            // ブロック単位のチーム数
            $teamByBlock =  floor(count($teams) / $blockNum);

            // 奇数チームになるシート数
            $theam3 = (count($teams) % $teamBySheet);
            // ブロックごとの3チーム数
            $blockTheam3 = ceil($theam3 / $blockNum);

            $j = 0;
            $hajime = array();
            $ato = array();
            $teamByBlock = array();

            while ($j < $blockNum) {
                $teamByBlock[$j] = floor(count($teams) / $blockNum);
                if ($j + 1 == $blockNum) {
                    $teamByBlock[$j] += count($teams) % $blockNum;
                }
                $hajime[$j] = floor($blockTheam3 / 2);
                $ato[$j] = floor($blockTheam3 / 2) + $blockTheam3 % 2;
                $j++;
            }
            $block = array();
            for ( $i = 0; $i < $blockNum; $i++ ) {
                $block[] = chr(65 + $i);
            }

            $i = 0;
            $j = 0;
            $tonament = array();
            foreach ($block as $key => $value) {
                while ($i < $sheetNum) {
                  if ($key < $sheetNum) {
                      $blockStr = $block[$key];
                  } else {
                      $blockStr = $block[($key % $sheetNum)];
                  }
                  $h = 0;
                  while ($h < $teamBySheet) {
                      if ($h == ($teamBySheet - 1) && $key < 8 &&
                      $key < $hajime[floor($key / $sheetNum)]) {
                          $h++;
                          continue;
                      } elseif ($h == ($teamBySheet - 1) && 7 < $key &&
                      $key > (15 - $ato[floor($key / $sheetNum)])) {
                          $h++;
                          continue;
                      }
                      if (empty($teams[$j])) {
                          break;
                      }

                      $team = Team::find($teams[$j]['id']);
                      $team->sheet = $i + 1;
                      $team->block = $blockStr;
                      $team->number = $h + 1;
                      $team->update();
                      $j++;
                      $h++;
                  }
                  $i++;
              }
            }

            FlashMessageService::success('作成が完了しました');

        } catch (\Exception $e) {
            report($e);
            FlashMessageService::error('作成が失敗しました');
        }

        return redirect()->route('tournament.index');
    }

    public function edit(Request $request)
    {
        $event = $request->session()->get('event');
        $selectBlock = $request->block;
        if (!$selectBlock) {
            $selectBlock = 'A';
        }
        $blocks = Team::getBlocks($event);
        $sheets = Team::getSheets($event, $selectBlock);

        $teams = array();
        foreach ($sheets as $key => $value) {
            $i = 1;
            while ($i <= 4) {
                $team = Team::where('event_id', $event)
                ->where('block', $selectBlock)
                ->where('sheet', $value->sheet)
                ->where('number', $i)
                ->first();
                if ($team) {
                    $teams[] = $team;
                } else {
                    $teams[] = (object)[
                      'id' => $selectBlock.'_'.$value->sheet.'_'.$i.'_'.$event,
                      'number' => $i,
                      'name' => '',
                      'sheet' => $value->sheet,
                      'abstention' => 0,
                  ];
                }
                $i++;
            }
        }

        return view('tournament.edit',
        compact('blocks', 'selectBlock', 'teams', 'sheets'));
    }

    public function progress(Request $request)
    {
        $event = $request->session()->get('event');
        // 参加している対戦表のチェック
        $member = null;
        if (Auth::user()->role == config('user.role.member')) {
            $member = Member::join('teams', 'teams.id', 'members.team_id')
            ->where('event_id', $event)
            ->where('user_id', Auth::id())->first();
        }
        if ($member) {
            $selectBlock = $member->team->block;
        } else {
            $selectBlock = $request->block;
        }
        if (!$selectBlock) {
            $selectBlock = 1;
        }
        $selectSheet = 'progress';

        $blocks = Team::getBlocks($event);
        $sheets = Team::getSheets($event, $selectBlock);

        $progress = array();
        $results = Result::where('event_id', $event)
        ->where('block', $selectBlock)
        ->orderBy('sheet')
        ->orderBy('turn')
        ->get();
        foreach ($results as $key => $v) {
            if ($v->winteam->number == 1 || $v->loseteam->number == 1) {
                $num = 0;
            } else {
                $num = 1;
            }
            $progress[$v->sheet][$v->turn][$num] = true;
        }

        return view('tournament.progress',
        compact('selectBlock', 'selectSheet', 'blocks', 'sheets', 'progress'));
    }

    public function maingame(Request $request)
    {
        $event_id = $request->session()->get('event');
        $event = Event::find($event_id);
        // 参加している対戦表のチェック
        $member = null;
        if (Auth::user()->role == config('user.role.member')) {
            $member = Member::join('teams', 'teams.id', 'members.team_id')
            ->where('event_id', $event->id)
            ->where('user_id', Auth::id())->first();
        }
        if ($member) {
            $selectBlock = $member->team->block;
        } else {
            $selectBlock = $request->block;
        }
        if (!$selectBlock) {
            $selectBlock = 1;
        }
        $selectSheet = 'maingame';

        $blocks = Team::getBlocks($event->id);
        $sheets = Team::getSheets($event->id, $selectBlock);

        // 本戦トーナメント構成
        $teams = array();
        $result = MainGame::orderBy('turn')->get();
        foreach ($result as $key => $value) {
            $teams[$value->turn]['sheet'] = $value->sheet;
            $teams[$value->turn]['order'] = $value->order;
        }
        $gameCnt = 5;

        return view('tournament.maingame',
        compact('selectBlock', 'selectSheet', 'blocks', 'sheets', 'teams', 'gameCnt'));
    }

    public function teamlist(Request $request)
    {
        $event_id = $request->session()->get('event');
        $event = Event::find($event_id);
        // 参加している対戦表のチェック
        $member = null;
        if (Auth::user()->role == config('user.role.member')) {
            $member = Member::join('teams', 'teams.id', 'members.team_id')
            ->where('event_id', $event->id)
            ->where('user_id', Auth::id())->first();
        }
        if ($member) {
            $selectBlock = $member->team->block;
        } else {
            $selectBlock = $request->block;
        }
        if (!$selectBlock) {
            $selectBlock = 1;
        }
        $selectSheet = 'teamlist';

        $blocks = Team::getBlocks($event->id);
        $sheets = Team::getSheets($event->id, $selectBlock);

        $teams = Team::where('block', $selectBlock)
        ->where('event_id', $event_id)
        ->orderBy('sheet')
        ->orderBy('number')
        ->get();

        return view('tournament.teamlist',
        compact('selectBlock', 'selectSheet', 'blocks', 'sheets', 'teams', 'event'));
    }
}
